<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Contracts\FundingProviderAdapter;
use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\OrphanDetectableInterface;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E27 T074/T075 — Funding module orphan detection and auto-recovery.
 *
 * Provider re-query via FundingProviderAdapter::queryStatus():
 * - completed → FundingCreditService::credit() posts wallet credit and emits FundingTransactionCompleted
 * - failed    → mark Failed and emit FundingTransactionFailed
 * - ambiguous → leave in timeout_pending_review for manual operator review
 */
final class FundingOrphanRecoveryService implements OrphanDetectableInterface
{
    public function __construct(
        private readonly FundingProviderAdapter  $adapter,
        private readonly FundingCreditService    $creditService,
        private readonly EventPublisherInterface $events,
    ) {}

    public function findOrphanedTransactionIds(Carbon $threshold): array
    {
        return FundingTransactionModel::query()
            ->whereIn('status', [
                FundingStatus::PendingProvider->value,
                FundingStatus::TimeoutPendingReview->value,
            ])
            ->where('updated_at', '<', $threshold)
            ->pluck('id')
            ->toArray();
    }

    public function recoverOrphanedTransaction(string $transactionId, string $correlationId): string
    {
        $tx = FundingTransactionModel::find($transactionId);

        if ($tx === null) {
            Log::warning('funding.orphan.transaction_not_found', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
            ]);
            return 'skipped';
        }

        if (!in_array($tx->status, [FundingStatus::PendingProvider, FundingStatus::TimeoutPendingReview], true)) {
            return 'skipped';
        }

        if ($tx->provider_reference_id === null) {
            Log::info('funding.orphan.no_provider_reference', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
            ]);
            return 'skipped';
        }

        try {
            $result = $this->adapter->queryStatus($tx->provider_reference_id);
        } catch (\Throwable $e) {
            Log::warning('funding.orphan.provider_query_failed', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
            return 'skipped';
        }

        $providerStatus = $result['status'] ?? 'ambiguous';

        if ($providerStatus === 'completed') {
            try {
                $this->creditService->credit([
                    'transaction_id'        => $transactionId,
                    'provider_reference_id' => $tx->provider_reference_id,
                    'correlation_id'        => $correlationId,
                ]);

                Log::info('funding.orphan.recovered', [
                    'transaction_id' => $transactionId,
                    'correlation_id' => $correlationId,
                ]);
                return 'recovered';
            } catch (\Throwable $e) {
                Log::error('funding.orphan.credit_failed', [
                    'transaction_id' => $transactionId,
                    'error'          => $e->getMessage(),
                    'correlation_id' => $correlationId,
                ]);
                return 'skipped';
            }
        }

        if ($providerStatus === 'failed') {
            DB::connection('platform')->transaction(function () use ($tx, $correlationId): void {
                $locked = FundingTransactionModel::where('id', $tx->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return;
                }

                $locked->status         = FundingStatus::Failed;
                $locked->failure_reason = FundingFailureReason::ProviderDeclined;
                $locked->save();

                $this->events->publish(new FundingTransactionFailed(
                    fundingTransactionId: $tx->id,
                    customerId:           $tx->customer_id,
                    failureReason:        FundingFailureReason::ProviderDeclined->value,
                    correlationId:        $correlationId,
                ));

                $this->events->publish(new TransactionReversedEvent(
                    transactionId: $tx->id,
                    moduleType:    'funding',
                    walletId:      $tx->wallet_id,
                    amount:        $tx->gross_amount,
                    currency:      $tx->pocket_currency_code,
                    correlationId: $correlationId,
                    reversedAt:    now()->toIso8601String(),
                ));
            });

            Log::info('funding.orphan.reversed', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
            ]);
            return 'reversed';
        }

        // ambiguous — leave for manual review
        Log::info('funding.orphan.ambiguous_left_for_review', [
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
        ]);
        return 'skipped';
    }
}
