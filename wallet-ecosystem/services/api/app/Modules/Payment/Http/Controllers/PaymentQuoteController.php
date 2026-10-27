<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PaymentQuoteController extends Controller
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface $intentRepository,
        private readonly PaymentQuoteRepositoryInterface  $quoteRepository,
    ) {
    }

    /**
     * GET /v1/payment/intents/{id}/quote — retrieve the active quote for a payment intent.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user    = $request->user();
        $actorId = $user?->id;

        if ($actorId === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $intent = $this->intentRepository->findById($id);
        if ($intent === null) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        // Allow payer (owns the intent) or admin (has payment.intents.read at broader scope)
        if ($intent->payer_actor_id !== $actorId && ! $request->user()?->hasPermission('payment.admin')) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $quote = $this->quoteRepository->findActiveForIntent($id);
        if ($quote === null) {
            return response()->json([
                'error' => 'No active quote found for this intent',
                'code'  => 'NO_ACTIVE_QUOTE',
            ], 422);
        }

        return response()->json([
            'data' => [
                'id'                 => $quote->id,
                'payment_intent_id'  => $quote->payment_intent_id,
                'version'            => $quote->version,
                'quote_status'       => $quote->quote_status->value,
                'base_amount_minor'  => $quote->base_amount_minor,
                'fee_amount_minor'   => $quote->fee_amount_minor,
                'total_amount_minor' => $quote->total_amount_minor,
                'currency_code'      => $quote->currency_code,
                'provider_code'      => $quote->provider_code,
                'rounding_mode'      => $quote->rounding_mode,
                'fee_snapshot'       => $quote->fee_rule_snapshot,
                'obligation_snapshot' => $quote->obligation_snapshot,
                'expires_at'         => $quote->expires_at?->toIso8601String(),
                'created_at'         => $quote->created_at?->toIso8601String(),
            ],
        ]);
    }
}
