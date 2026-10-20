<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Contracts\FundingProviderAdapter;
use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Domain\Events\FundingTransactionLateCallback;
use App\Modules\Funding\Domain\Exceptions\DuplicateCallbackException;
use App\Modules\Funding\Infrastructure\Models\FundingProviderCallbackModel;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Processes inbound provider callbacks inline (not queued).
 *
 * Responsibilities:
 * 1. Verify HMAC signature via FundingProviderAdapter::verifyCallback().
 * 2. Persist raw callback payload (FundingProviderCallbackModel).
 * 3. Idempotency guard: duplicate provider_reference_id → DuplicateCallbackException.
 * 4. On success: delegate to FundingCreditService to post journal and complete tx.
 * 5. On failure: transition transaction to failed with failure_reason.
 *
 * Note: Callbacks are processed synchronously — wallet credited before 200 is returned.
 */
final class ProviderCallbackService
{
    public function __construct(
        private readonly FundingProviderAdapter    $adapter,
        private readonly FundingCreditService      $creditService,
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   payload:        array,
     *   signature:      string,
     *   provider:       string,
     *   correlation_id: string,
     * } $request
     *
     * @throws \App\Modules\Funding\Domain\Exceptions\DuplicateCallbackException
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  400 on invalid signature (SR-E08-001)
     */
    public function handle(array $request): void
    {
        $payload       = $request['payload'];
        $signature     = $request['signature'];
        $provider      = $request['provider'];
        $correlationId = $request['correlation_id'];

        // Verify HMAC
        $verified = $this->adapter->verifyCallback($payload, $signature, $provider);

        // Always persist the raw callback (for audit trail), marking signature validity
        $callbackId = (string) Uuid::uuid7();
        DB::transaction(function () use ($callbackId, $provider, $payload, $signature, $verified): void {
            $cb                    = new FundingProviderCallbackModel();
            $cb->id                = $callbackId;
            $cb->provider          = $provider;
            $cb->raw_payload       = $payload;
            $cb->signature         = $signature;
            $cb->signature_valid   = $verified['valid'];
            $cb->save();
        });

        if (! $verified['valid']) {
            abort(400, 'Invalid callback signature.');
        }

        $providerRefId = $verified['provider_reference_id'];

        // Idempotency guard: distinguish completed (silent replay) from failed (EC-E08-02 anomaly)
        $existingTx = FundingTransactionModel::where('provider_reference_id', $providerRefId)
            ->whereIn('status', [FundingStatus::Completed->value, FundingStatus::Failed->value])
            ->first();

        if ($existingTx !== null) {
            if ($existingTx->status === FundingStatus::Completed) {
                // Normal duplicate — already credited; idempotent 200
                throw new DuplicateCallbackException($providerRefId);
            }

            // EC-E08-02: success callback on an already-failed transaction — emit alert, do NOT re-credit
            if ($verified['status'] === 'success') {
                $this->events->publish(new FundingTransactionLateCallback(
                    fundingTransactionId: $existingTx->id,
                    walletId:             $existingTx->wallet_id,
                    currencyCode:         $existingTx->pocket_currency_code,
                    amount:               $existingTx->gross_amount,
                    providerReferenceId:  $providerRefId,
                    occurredAt:           now()->toIso8601String(),
                    correlationId:        $correlationId,
                ));
                $this->metrics->increment('funding.callback.late', ['provider' => $provider]);
            }
            // In both cases (late-success or late-failure) return without further processing
            throw new DuplicateCallbackException($providerRefId);
        }

        // Find the associated transaction
        $tx = FundingTransactionModel::where('provider_reference_id', $providerRefId)
            ->orWhere('transaction_reference', $payload['transaction_reference'] ?? '')
            ->whereIn('status', [FundingStatus::Processing->value])
            ->first();

        if ($tx === null) {
            $this->metrics->increment('funding.callback.unmatched', ['provider' => $provider]);
            return;
        }

        if ($verified['status'] === 'success') {
            $this->creditService->credit([
                'transaction_id'        => $tx->id,
                'provider_reference_id' => $providerRefId,
                'correlation_id'        => $correlationId,
            ]);
        } else {
            // Mark failed
            DB::transaction(function () use ($tx): void {
                $tx->refresh();
                if ($tx->status === FundingStatus::Processing) {
                    $tx->status         = FundingStatus::Failed;
                    $tx->failure_reason = FundingFailureReason::ProviderDeclined;
                    $tx->save();
                }
            });

            $this->events->publish(new FundingTransactionFailed(
                fundingTransactionId: $tx->id,
                customerId:           $tx->customer_id,
                failureReason:        FundingFailureReason::ProviderDeclined->value,
                correlationId:        $correlationId,
            ));

            $this->metrics->increment('funding.callback.provider_failed', ['provider' => $provider]);
        }

        $this->audit->record(new AuditEventData(
            action:       'funding.callback.processed',
            actorType:    'SYSTEM',
            resourceType: 'funding_provider_callback',
            correlationId: $correlationId,
            outcome:      $verified['status'] === 'success' ? 'success' : 'failure',
            resourceId:   $callbackId,
        ));
    }
}
