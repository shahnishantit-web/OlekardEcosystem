<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Customer;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Http\Resources\CustomerRefundListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * T031: US6 — Read-only refund status view for authenticated consumers.
 *
 * GET /v1/payment/intents/{intentId}/refunds
 * Permission: payment.refunds.view (enforced via route middleware)
 *
 * Ownership: intentId must belong to the authenticated user (payer_actor_id match).
 * No state mutation is permitted on this endpoint.
 */
final class CustomerRefundStatusController extends Controller
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface $intentRepository,
        private readonly PaymentRefundRepositoryInterface $refundRepository,
    ) {
    }

    public function index(Request $request, string $intentId): JsonResponse
    {
        $intent = $this->intentRepository->findById($intentId);

        if ($intent === null) {
            return response()->json(['message' => 'Payment intent not found.'], 404);
        }

        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        if ($intent->payer_actor_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $refunds = $this->refundRepository->findByIntentId($intentId);

        $confirmedTotal = 0;
        $hasPending     = false;

        foreach ($refunds as $refund) {
            if ($refund->status->fundsReturned()) {
                $confirmedTotal += $refund->amount_minor;
            }
            if (! $refund->status->isTerminal()) {
                $hasPending = true;
            }
        }

        $remainingRefundable = max(0, ($intent->amount_minor ?? 0) - $confirmedTotal);

        return response()->json([
            'data' => CustomerRefundListResource::collection($refunds)->toArray($request),
            'meta' => [
                'total_confirmed_minor'    => $confirmedTotal,
                'remaining_refundable_minor' => $remainingRefundable,
                'has_pending_refunds'      => $hasPending,
            ],
        ]);
    }
}
