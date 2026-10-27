<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Payment\Application\Services\DisputeService;
use Illuminate\Support\Facades\Log;

/**
 * P16 inbox consumer for bank.dispute.callback events (E25 ingress).
 *
 * Routes the authenticated provider event to DisputeService::receive().
 * Deduplication is handled inside DisputeService — this handler is idempotent.
 */
final class BankDisputeCallbackHandler
{
    public function __construct(
        private readonly DisputeService $disputeService,
    ) {
    }

    /**
     * Handle an incoming bank.dispute.callback event payload.
     *
     * @param array{
     *   provider_code: string,
     *   provider_dispute_reference: string,
     *   payment_attempt_id: string,
     *   case_type: string,
     *   disputed_amount_minor: int,
     *   currency_code: string,
     *   reason_code?: string|null,
     *   response_deadline?: string|null,
     *   correlation_id?: string,
     * } $payload
     * @return bool true on success; false on unrecoverable error
     */
    public function handle(array $payload): bool
    {
        try {
            $this->disputeService->receive($payload);
            return true;
        } catch (\InvalidArgumentException $e) {
            // Unknown attempt — ReconciliationException already recorded in DisputeService
            Log::warning('BankDisputeCallbackHandler: unmatched dispute event', [
                'provider_code'              => $payload['provider_code'] ?? 'unknown',
                'provider_dispute_reference' => $payload['provider_dispute_reference'] ?? 'unknown',
                'error'                      => $e->getMessage(),
                'correlation_id'             => $payload['correlation_id'] ?? null,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('BankDisputeCallbackHandler: unexpected error', [
                'provider_code'  => $payload['provider_code'] ?? 'unknown',
                'error'          => $e->getMessage(),
                'correlation_id' => $payload['correlation_id'] ?? null,
            ]);
            return false;
        }
    }
}
