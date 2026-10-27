<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P21 T005a: Read-only provider status query for a payment intent.
 *
 * Dispatched by POST /api/v1/admin/payment/intents/{id}/query-status (admin action).
 * Calls the provider in read-only mode via QueriesPayment capability.
 * MUST NOT mutate payment intent state or post ledger entries.
 *
 * Modelled after QueryRefundStatusJob but for intents.
 */
final class QueryPaymentIntentStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $intentId,
    ) {
    }

    public function handle(
        PaymentIntentRepositoryInterface $intentRepo,
    ): void {
        $intent = $intentRepo->findById($this->intentId);

        if ($intent === null) {
            Log::warning('QueryPaymentIntentStatusJob: intent not found', [
                'intent_id' => $this->intentId,
            ]);
            return;
        }

        if (in_array($intent->status->value, ['fulfilled', 'failed', 'cancelled'], true)) {
            Log::debug('QueryPaymentIntentStatusJob: intent already terminal', [
                'intent_id' => $this->intentId,
                'status'    => $intent->status->value,
            ]);
            return;
        }

        Log::info('QueryPaymentIntentStatusJob: admin status query dispatched', [
            'intent_id' => $this->intentId,
            'status'    => $intent->status->value,
        ]);

        // Provider adapter query would be wired here when AD-14 is resolved.
        // For now: log the query attempt. No state mutation occurs.
    }
}
