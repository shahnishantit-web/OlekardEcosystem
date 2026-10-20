<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reconciliation\Application\Jobs\ExecuteSettlementJob;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Enums\SettlementStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\SettlementBatch;
use App\Modules\Reconciliation\Domain\Models\SettlementItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SettlementService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly ReconciliationMetricsInterface $metrics,
    ) {}

    /**
     * Calculate and atomically create a settlement batch from reconciled items in the given batch.
     * US-RECON-012 + US-RECON-013.
     */
    public function calculateAndCreate(
        string $reconBatchId,
        string $settlementType,
        string $periodStart,
        string $periodEnd,
        string $currencyCode,
        string $makerId,
        string $idempotencyKey,
        ?string $providerCode = null,
    ): SettlementBatch {
        $existing = SettlementBatch::on('reconciliation')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $reconBatch = ReconciliationBatch::on('reconciliation')->find($reconBatchId);

        if ($reconBatch === null) {
            throw new NotFoundHttpException("Reconciliation batch {$reconBatchId} not found.");
        }

        $matchedItems = $reconBatch->items()
            ->where('status', ItemStatus::Matched->value)
            ->where('currency_code', $currencyCode)
            ->get();

        if ($matchedItems->isEmpty()) {
            throw new UnprocessableEntityHttpException("No matched items found in batch {$reconBatchId} for currency {$currencyCode}.");
        }

        return DB::connection('reconciliation')->transaction(function () use (
            $matchedItems, $settlementType, $periodStart, $periodEnd,
            $currencyCode, $makerId, $idempotencyKey, $providerCode
        ): SettlementBatch {
            $grossAmount = '0';
            foreach ($matchedItems as $item) {
                $grossAmount = $this->bcAdd($grossAmount, (string) abs((float) $item->amount), 4);
            }

            $feeRate   = config('reconciliation.default_fee_rate', '0');
            $feeAmount = $this->bcMul($grossAmount, (string) $feeRate, 4);
            $netAmount = $this->bcSub($grossAmount, $feeAmount, 4);

            $settlementId = (string) Str::orderedUuid();

            $settlement = SettlementBatch::on('reconciliation')->create([
                'id'              => $settlementId,
                'idempotency_key' => $idempotencyKey,
                'settlement_type' => $settlementType,
                'provider_code'   => $providerCode,
                'period_start'    => $periodStart,
                'period_end'      => $periodEnd,
                'currency_code'   => $currencyCode,
                'gross_amount'    => $grossAmount,
                'fee_amount'      => $feeAmount,
                'net_amount'      => $netAmount,
                'status'          => SettlementStatus::Draft->value,
                'maker_id'        => $makerId,
                'metadata'        => [],
            ]);

            foreach ($matchedItems as $item) {
                SettlementItem::on('reconciliation')->create([
                    'id'                      => (string) Str::orderedUuid(),
                    'settlement_batch_id'     => $settlementId,
                    'reconciliation_item_id'  => $item->id,
                    'amount'                  => abs((float) $item->amount),
                ]);
            }

            $this->metrics->incrementSettlementAction('created');
            $this->metrics->recordSettlementAmount($currencyCode, $netAmount);

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.settlement.created',
                actorType:     'admin',
                resourceType:  'settlement_batch',
                correlationId: $settlementId,
                outcome:       'success',
                actorId:       $makerId,
                resourceId:    $settlementId,
                afterState:    [
                    'settlement_type' => $settlementType,
                    'gross_amount'    => $grossAmount,
                    'net_amount'      => $netAmount,
                    'currency_code'   => $currencyCode,
                ],
            ));

            return $settlement;
        });
    }

    /**
     * Submit a draft settlement for approval. 409 if already submitted.
     * US-RECON-013 (transition).
     */
    public function submitForApproval(string $settlementId, string $makerId): SettlementBatch
    {
        $settlement = $this->find($settlementId);

        // Natural idempotency guard — 409 if not draft
        if (! $settlement->isDraft()) {
            throw new ConflictHttpException("Settlement {$settlementId} is already in status {$settlement->status->value}; cannot submit again.");
        }

        $settlement->update(['status' => SettlementStatus::PendingApproval->value]);

        $this->metrics->incrementSettlementAction('submitted');

        $this->audit->record(new AuditEventData(
            action:        'reconciliation.settlement.submitted',
            actorType:     'admin',
            resourceType:  'settlement_batch',
            correlationId: $settlementId,
            outcome:       'success',
            actorId:       $makerId,
            resourceId:    $settlementId,
            afterState:    ['status' => SettlementStatus::PendingApproval->value],
        ));

        return $settlement->fresh();
    }

    /**
     * Approve a pending_approval settlement. Maker-checker: checker_id != maker_id.
     * US-RECON-014.
     */
    public function approveSettlement(string $settlementId, string $checkerId, string $idempotencyKey): SettlementBatch
    {
        return DB::connection('reconciliation')->transaction(function () use ($settlementId, $checkerId): SettlementBatch {
            $settlement = SettlementBatch::on('reconciliation')
                ->where('id', $settlementId)
                ->lockForUpdate()
                ->first();

            if ($settlement === null) {
                throw new NotFoundHttpException("Settlement {$settlementId} not found.");
            }

            // Idempotency replay
            if ($settlement->status === SettlementStatus::Approved && $settlement->checker_id === $checkerId) {
                return $settlement;
            }

            if (! $settlement->isPendingApproval()) {
                throw new UnprocessableEntityHttpException("Settlement {$settlementId} is not pending approval (status: {$settlement->status->value}).");
            }

            // Maker-checker enforcement
            if ($settlement->maker_id === $checkerId) {
                throw new UnprocessableEntityHttpException("Settlement maker and checker must be different users (maker-checker policy).");
            }

            $settlement->update([
                'status'      => SettlementStatus::Approved->value,
                'checker_id'  => $checkerId,
                'approved_at' => now(),
            ]);

            $this->metrics->incrementSettlementAction('approved');

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.settlement.approved',
                actorType:     'admin',
                resourceType:  'settlement_batch',
                correlationId: $settlementId,
                outcome:       'success',
                actorId:       $checkerId,
                resourceId:    $settlementId,
                afterState:    ['status' => SettlementStatus::Approved->value],
            ));

            return $settlement->fresh();
        });
    }

    /**
     * Reject a pending_approval settlement.
     */
    public function rejectSettlement(string $settlementId, string $checkerId, string $rejectionReason): SettlementBatch
    {
        return DB::connection('reconciliation')->transaction(function () use ($settlementId, $checkerId, $rejectionReason): SettlementBatch {
            $settlement = SettlementBatch::on('reconciliation')
                ->where('id', $settlementId)
                ->lockForUpdate()
                ->first();

            if ($settlement === null) {
                throw new NotFoundHttpException("Settlement {$settlementId} not found.");
            }

            if (! $settlement->isPendingApproval()) {
                throw new UnprocessableEntityHttpException("Settlement {$settlementId} is not pending approval (status: {$settlement->status->value}).");
            }

            if ($settlement->maker_id === $checkerId) {
                throw new UnprocessableEntityHttpException("Settlement maker and checker must be different users (maker-checker policy).");
            }

            $settlement->update([
                'status'           => SettlementStatus::Rejected->value,
                'checker_id'       => $checkerId,
                'rejection_reason' => $rejectionReason,
                'rejected_at'      => now(),
            ]);

            $this->metrics->incrementSettlementAction('rejected');

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.settlement.rejected',
                actorType:     'admin',
                resourceType:  'settlement_batch',
                correlationId: $settlementId,
                outcome:       'success',
                actorId:       $checkerId,
                resourceId:    $settlementId,
                afterState:    ['status' => SettlementStatus::Rejected->value, 'reason' => $rejectionReason],
            ));

            return $settlement->fresh();
        });
    }

    /**
     * Dispatch execution of an approved settlement.
     * US-RECON-015.
     */
    public function executeSettlement(string $settlementId, string $executorId, string $idempotencyKey): SettlementBatch
    {
        $settlement = $this->find($settlementId);

        if (! $settlement->isApproved()) {
            throw new UnprocessableEntityHttpException("Settlement {$settlementId} is not approved (status: {$settlement->status->value}).");
        }

        $settlement->update(['status' => SettlementStatus::Executing->value]);

        ExecuteSettlementJob::dispatch($settlementId, $executorId);

        $this->metrics->incrementSettlementAction('executing');

        return $settlement->fresh();
    }

    public function listSettlements(
        ?string $status = null,
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = SettlementBatch::on('reconciliation')
            ->orderBy('created_at', 'desc');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function getSettlement(string $settlementId): SettlementBatch
    {
        $settlement = SettlementBatch::on('reconciliation')
            ->with('items.reconciliationItem')
            ->find($settlementId);

        if ($settlement === null) {
            throw new NotFoundHttpException("Settlement {$settlementId} not found.");
        }

        return $settlement;
    }

    private function bcAdd(string $a, string $b, int $scale = 4): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, $scale);
        }
        return number_format((float) $a + (float) $b, $scale, '.', '');
    }

    private function bcMul(string $a, string $b, int $scale = 4): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }
        return number_format((float) $a * (float) $b, $scale, '.', '');
    }

    private function bcSub(string $a, string $b, int $scale = 4): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $scale);
        }
        return number_format((float) $a - (float) $b, $scale, '.', '');
    }

    private function find(string $settlementId): SettlementBatch
    {
        $settlement = SettlementBatch::on('reconciliation')->find($settlementId);

        if ($settlement === null) {
            throw new NotFoundHttpException("Settlement {$settlementId} not found.");
        }

        return $settlement;
    }
}
