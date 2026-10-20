<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * T141: Handles refund requests for a PaymentIntent.
 *
 * NEVER auto-triggers financial reversal without verified provider confirmation.
 */
final class PaymentRefundController extends Controller
{
    public function __construct(
        private readonly RefundService                    $refundService,
        private readonly PaymentIntentRepositoryInterface $intentRepository,
    ) {
    }

    /**
     * POST /v1/payment/intents/{intentId}/refunds
     * Permission: payment.refunds.request
     */
    public function store(Request $request, string $intentId): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason'       => ['nullable', 'string', 'max:500'],
        ]);

        // Amount must be an integer — enforce no float
        if (! is_int($validated['amount_minor'])) {
            return response()->json(['message' => 'amount_minor must be an integer in minor units.'], 422);
        }

        $intent = $this->intentRepository->findById($intentId);

        if ($intent === null) {
            return response()->json(['message' => 'Payment intent not found.'], 404);
        }

        // Validate intent is in a refundable state
        $refundableStatuses = [
            PaymentIntentStatus::Succeeded,
            PaymentIntentStatus::PartiallyRefunded,
        ];

        if (! in_array($intent->status, $refundableStatuses, true)) {
            return response()->json([
                'message' => 'Payment intent is not in a refundable state.',
                'code'    => 'INTENT_NOT_REFUNDABLE',
            ], 422);
        }

        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        try {
            $refund = $this->refundService->request([
                'payment_intent_id'  => $intentId,
                'payment_attempt_id' => $intent->succeeded_attempt_id ?? '',
                'provider_code'      => $intent->provider_code ?? '',
                'amount_minor'       => $validated['amount_minor'],
                'currency_code'      => $intent->currency_code ?? '',
                'requested_by'       => $user->id,
                'idempotency_key'    => $request->header('Idempotency-Key') ?? (string) Uuid::uuid7(),
                'correlation_id'     => $request->header('X-Correlation-Id') ?? (string) Uuid::uuid7(),
                'reason'             => $validated['reason'] ?? null,
            ]);

            return response()->json([
                'data' => [
                    'id'                   => $refund->id,
                    'payment_intent_id'    => $refund->payment_intent_id,
                    'amount_minor'         => $refund->amount_minor,
                    'status'               => $refund->status->value,
                    'requested_at'         => $refund->requested_at?->toIso8601String(),
                ],
            ], 201);
        } catch (RefundLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'REFUND_LIMIT_EXCEEDED',
            ], 422);
        } catch (CapabilityUnsupportedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'CAPABILITY_UNSUPPORTED',
            ], 422);
        }
    }
}
