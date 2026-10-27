<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\EventHandlers;

use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * T025: P11 inbox consumer for bank.refund.callback events.
 *
 * Matches the refund by provider_submitted_ref.
 * Idempotent: already-terminal refunds are ack'd without processing.
 * AD-16: bank refund fee accounting deferred — handled in RefundService::confirm().
 */
final class BankRefundCallbackHandler
{
    public function __construct(
        private readonly PaymentRefundRepositoryInterface $refundRepo,
        private readonly RefundService                    $refundService,
    ) {
    }

    /**
     * Handle an incoming bank.refund.callback event payload.
     *
     * @param array{
     *   event: string,
     *   provider_submitted_ref: string,
     *   status: 'confirmed'|'declined'|'pending',
     *   decline_reason?: string,
     *   correlation_id: string,
     * } $payload
     * @return bool true on success; false when the event cannot be matched
     */
    public function handle(array $payload): bool
    {
        $providerRef   = $payload['provider_submitted_ref'] ?? '';
        $status        = $payload['status'] ?? '';
        $correlationId = $payload['correlation_id'] ?? "bank-callback:{$providerRef}";

        $refund = $this->refundRepo->findByProviderSubmittedRef($providerRef);

        if ($refund === null) {
            Log::warning('BankRefundCallbackHandler: refund not found by provider_submitted_ref', [
                'provider_submitted_ref' => $providerRef,
                'correlation_id'         => $correlationId,
            ]);
            return false;
        }

        // Idempotency gate: already terminal — ack without processing
        if ($refund->status->isTerminal()) {
            Log::debug('BankRefundCallbackHandler: refund already terminal, acking duplicate callback', [
                'refund_id' => $refund->id,
                'status'    => $refund->status->value,
            ]);
            return true;
        }

        if ($status === 'confirmed') {
            $this->refundService->confirm($refund->id, $providerRef, $correlationId);
            return true;
        }

        if ($status === 'declined') {
            $reason = $payload['decline_reason'] ?? 'Bank declined refund';
            $this->refundService->definitivelyFail($refund->id, $reason, $correlationId);
            return true;
        }

        // Pending — no state change needed; QueryRefundStatusJob will poll
        Log::debug('BankRefundCallbackHandler: refund still pending, no action', [
            'refund_id'         => $refund->id,
            'callback_status'   => $status,
        ]);
        return true;
    }
}
