<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionType;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReconciliationMatchingService
{
    private const CONFIDENCE_EXACT = 100;
    private const CONFIDENCE_FUZZY = 75;
    private const CONFIDENCE_NONE  = 0;

    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly ReconciliationMetricsInterface $metrics,
        private readonly ReconciliationExceptionService $exceptionService,
    ) {}

    /**
     * Three-pass matching for all pending items in a batch.
     * Called by ProcessReconciliationBatchJob.
     */
    public function matchBatch(string $batchId): void
    {
        $batch = ReconciliationBatch::on('reconciliation')->find($batchId);

        if ($batch === null) {
            Log::warning('reconciliation.matching.batch_not_found', ['batch_id' => $batchId]);
            return;
        }

        $pendingItems = $batch->items()->where('status', ItemStatus::Pending->value)->get();

        if ($pendingItems->isEmpty()) {
            $this->finalizeBatch($batch);
            return;
        }

        DB::connection('reconciliation')->transaction(function () use ($batch, $pendingItems): void {
            $this->runThreePassMatching($pendingItems, $batch);
            $this->finalizeBatch($batch);
        });

        $this->audit->record(new AuditEventData(
            action:        'reconciliation.batch.matched',
            actorType:     'system',
            resourceType:  'reconciliation_batch',
            correlationId: $batchId,
            outcome:       'success',
            resourceId:    $batchId,
            afterState:    [
                'matched_count'   => $batch->fresh()->matched_count,
                'unmatched_count' => $batch->fresh()->unmatched_count,
            ],
        ));

        $this->metrics->incrementBatchProcessed($batch->source_type->value, BatchStatus::Completed->value);
    }

    public function triggerRematching(string $batchId, string $actorId): ReconciliationBatch
    {
        $batch = ReconciliationBatch::on('reconciliation')->find($batchId);

        if ($batch === null) {
            throw new NotFoundHttpException("Batch {$batchId} not found.");
        }

        if (! in_array($batch->status->value, [BatchStatus::Matched->value, BatchStatus::Completed->value, BatchStatus::Failed->value], true)) {
            throw new UnprocessableEntityHttpException("Batch {$batchId} is not eligible for rematching (status: {$batch->status->value}).");
        }

        $batch->update(['status' => BatchStatus::Processing->value]);

        // Reset unmatched items to pending for re-processing
        $batch->items()
            ->where('status', ItemStatus::Unmatched->value)
            ->update(['status' => ItemStatus::Pending->value, 'match_confidence' => 0]);

        \App\Modules\Reconciliation\Application\Jobs\ProcessReconciliationBatchJob::dispatch($batchId);

        return $batch->fresh();
    }

    public function markReconciled(string $itemId, string $actorId): ReconciliationItem
    {
        $item = ReconciliationItem::on('reconciliation')->find($itemId);

        if ($item === null) {
            throw new NotFoundHttpException("Reconciliation item {$itemId} not found.");
        }

        if ($item->status === ItemStatus::Matched) {
            return $item;
        }

        if (! in_array($item->status->value, [ItemStatus::Unmatched->value, ItemStatus::Exception->value], true)) {
            throw new UnprocessableEntityHttpException("Item {$itemId} cannot be marked reconciled from status {$item->status->value}.");
        }

        DB::connection('reconciliation')->transaction(function () use ($item): void {
            $item->update([
                'status'           => ItemStatus::Matched->value,
                'match_confidence' => self::CONFIDENCE_EXACT,
                'matched_at'       => now(),
            ]);

            $batch = $item->batch;
            if ($batch) {
                $batch->increment('matched_count');
                if ($batch->unmatched_count > 0) {
                    $batch->decrement('unmatched_count');
                }
            }
        });

        $this->audit->record(new AuditEventData(
            action:        'reconciliation.item.reconciled',
            actorType:     'admin',
            resourceType:  'reconciliation_item',
            correlationId: $itemId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $itemId,
            afterState:    ['status' => ItemStatus::Matched->value],
        ));

        $this->metrics->incrementItemMatched('manual');

        return $item->fresh();
    }

    /**
     * @param  Collection<int, ReconciliationItem>  $pendingItems
     */
    private function runThreePassMatching(Collection $pendingItems, ReconciliationBatch $batch): void
    {
        $remaining = $pendingItems;

        // Pass 1: Exact match (same amount + same date + matching reference)
        $remaining = $this->passExact($remaining);

        // Pass 2: Fuzzy match (±1 business day)
        $remaining = $this->passFuzzy($remaining);

        // Pass 3: All still-pending → unmatched → auto-create exception
        $this->passUnmatched($remaining, (string) $batch->id);
    }

    /**
     * @param  Collection<int, ReconciliationItem>  $items
     * @return Collection<int, ReconciliationItem>  Unmatched remainder
     */
    private function passExact(Collection $items): Collection
    {
        $remaining = collect();

        foreach ($items as $item) {
            $match = $this->findInternalTransaction(
                amount:          (string) $item->amount,
                currencyCode:    $item->currency_code,
                transactionDate: (string) $item->transaction_date->toDateString(),
                providerRef:     $item->provider_reference,
                fuzzyDays:       0,
            );

            if ($match !== null) {
                $item->update([
                    'status'                  => ItemStatus::Matched->value,
                    'internal_transaction_id' => $match['id'],
                    'match_confidence'         => self::CONFIDENCE_EXACT,
                    'matched_at'              => now(),
                ]);
                $this->metrics->incrementItemMatched('exact');
            } else {
                $remaining->push($item);
            }
        }

        return $remaining;
    }

    /**
     * @param  Collection<int, ReconciliationItem>  $items
     * @return Collection<int, ReconciliationItem>  Unmatched remainder
     */
    private function passFuzzy(Collection $items): Collection
    {
        $remaining = collect();

        foreach ($items as $item) {
            $match = $this->findInternalTransaction(
                amount:          (string) $item->amount,
                currencyCode:    $item->currency_code,
                transactionDate: (string) $item->transaction_date->toDateString(),
                providerRef:     $item->provider_reference,
                fuzzyDays:       1,
            );

            if ($match !== null) {
                $item->update([
                    'status'                  => ItemStatus::Matched->value,
                    'internal_transaction_id' => $match['id'],
                    'match_confidence'        => self::CONFIDENCE_FUZZY,
                    'matched_at'              => now(),
                ]);
                $this->metrics->incrementItemMatched('fuzzy');
            } else {
                $remaining->push($item);
            }
        }

        return $remaining;
    }

    /**
     * @param  Collection<int, ReconciliationItem>  $items
     */
    private function passUnmatched(Collection $items, string $batchId): void
    {
        $systemUserId = config('reconciliation.system_user_id', '00000000-0000-0000-0000-000000000000');

        foreach ($items as $item) {
            $item->update([
                'status'           => ItemStatus::Unmatched->value,
                'match_confidence' => self::CONFIDENCE_NONE,
            ]);

            $exceptionType = $item->amount >= 0
                ? ExceptionType::UnmatchedProvider
                : ExceptionType::UnmatchedBank;

            $this->exceptionService->createForItem($item, $exceptionType, $systemUserId);

            $this->metrics->incrementItemMatched('unmatched');
        }
    }

    /**
     * Cross-schema read against transaction.transaction_projections.
     * Same pattern as E19 Admin module cross-schema queries.
     *
     * @return array<string, mixed>|null
     */
    private function findInternalTransaction(
        string $amount,
        string $currencyCode,
        string $transactionDate,
        string $providerRef,
        int $fuzzyDays,
    ): ?array {
        $query = DB::connection('pgsql')
            ->table('transaction.transaction_projections')
            ->where('currency_code', $currencyCode)
            ->select(['id', 'amount', 'transaction_date', 'reference']);

        // Amount matching — exact to 4 decimal places using string comparison
        $query->whereRaw('ABS(amount - ?) < 0.00005', [bcadd($amount, '0', 4)]);

        if ($fuzzyDays === 0) {
            $query->whereDate('transaction_date', $transactionDate);
        } else {
            $dateObj = Carbon::parse($transactionDate);
            $query->whereBetween('transaction_date', [
                $dateObj->copy()->subWeekdays($fuzzyDays)->toDateString(),
                $dateObj->copy()->addWeekdays($fuzzyDays)->toDateString(),
            ]);
        }

        // Attempt reference match first; fall back to any date/amount match
        $byRef = (clone $query)->where('reference', $providerRef)->first();

        if ($byRef !== null) {
            return (array) $byRef;
        }

        $row = $query->first();

        return $row !== null ? (array) $row : null;
    }

    private function finalizeBatch(ReconciliationBatch $batch): void
    {
        $batch->refresh();
        $matched    = $batch->items()->where('status', ItemStatus::Matched->value)->count();
        $unmatched  = $batch->items()->where('status', ItemStatus::Unmatched->value)->count();
        $exceptions = $batch->items()->where('status', ItemStatus::Exception->value)->count();

        $status = ($unmatched + $exceptions) === 0
            ? BatchStatus::Completed->value
            : BatchStatus::Matched->value;

        $batch->update([
            'status'          => $status,
            'matched_count'   => $matched,
            'unmatched_count' => $unmatched,
            'exception_count' => $exceptions,
            'processed_at'    => now(),
        ]);

        Log::info('reconciliation.batch.matched', [
            'batch_id'        => $batch->id,
            'status'          => $status,
            'matched_count'   => $matched,
            'unmatched_count' => $unmatched,
        ]);
    }
}
