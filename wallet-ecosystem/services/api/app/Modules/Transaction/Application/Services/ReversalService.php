<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Transaction\Domain\Enums\ReversalStatus;
use App\Modules\Transaction\Domain\Exceptions\ReversalNotApprovableException;
use App\Modules\Transaction\Domain\Exceptions\ReversalNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use App\Modules\Transaction\Infrastructure\Models\ReversalAuditLogModel;
use App\Modules\Transaction\Infrastructure\Models\ReversalModel;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ReversalService
{
    public function __construct(
        private readonly TransactionProjectionRepository $transactionRepo,
        private readonly LedgerModuleContract            $ledger,
        private readonly IdempotencyServiceInterface     $idempotency,
        private readonly TransactionProjectionService    $projectionService,
    ) {}

    public function request(
        string $transactionId,
        string $requestedBy,
        string $idempotencyKey,
        ?string $reason,
        string $correlationId,
    ): ReversalModel {
        return DB::connection('platform')->transaction(function () use (
            $transactionId, $requestedBy, $idempotencyKey, $reason, $correlationId
        ): ReversalModel {
            $tx = $this->transactionRepo->findByIdForUpdate($transactionId);

            if ($tx === null) {
                throw TransactionNotFoundException::forId($transactionId);
            }

            $reversibleTypes = config('transaction.reversal.reversible_types', ['withdrawal', 'funding']);
            if (! in_array($tx->type->value, $reversibleTypes, true)) {
                throw TransactionNotReversibleException::typeNotSupported($tx->type->value);
            }

            if ($tx->status !== 'completed') {
                throw TransactionNotReversibleException::notCompleted($transactionId);
            }

            $activeReversal = ReversalModel::where('transaction_id', $transactionId)
                ->whereIn('status', [ReversalStatus::PendingApproval->value, ReversalStatus::Approved->value])
                ->first();

            if ($activeReversal !== null) {
                throw TransactionNotReversibleException::activeReversalExists($transactionId);
            }

            $reversal = ReversalModel::create([
                'id'              => (string) Uuid::uuid7(),
                'transaction_id'  => $transactionId,
                'status'          => ReversalStatus::PendingApproval->value,
                'requested_by'    => $requestedBy,
                'requested_at'    => now(),
                'idempotency_key' => $idempotencyKey,
                'correlation_id'  => $correlationId,
            ]);

            $this->appendAuditLog($reversal->id, $transactionId, 'reversal.requested', $requestedBy, 'admin', [
                'reason' => $reason,
            ]);

            Log::info('transaction.reversal.requested', [
                'reversal_id'    => $reversal->id,
                'transaction_id' => $transactionId,
                'requested_by'   => $requestedBy,
            ]);

            return $reversal;
        });
    }

    public function approve(
        string $reversalId,
        string $approvedBy,
        string $idempotencyKey,
        string $correlationId,
    ): ReversalModel {
        $storeKey = "reversal_approve:{$reversalId}";
        $cached   = $this->idempotency->findCompletedResponse($storeKey, $approvedBy);
        if ($cached !== null) {
            return ReversalModel::findOrFail($reversalId);
        }

        $lock = $this->idempotency->acquireLock($storeKey, $approvedBy, $reversalId, 'POST', "/v1/reversals/{$reversalId}/approve", $correlationId);

        try {
            return DB::connection('platform')->transaction(function () use (
                $reversalId, $approvedBy, $lock
            ): ReversalModel {
                $reversal = ReversalModel::lockForUpdate()->find($reversalId);

                if ($reversal === null) {
                    throw ReversalNotFoundException::forId($reversalId);
                }

                if ($reversal->status !== ReversalStatus::PendingApproval) {
                    throw ReversalNotApprovableException::wrongStatus($reversal->status->value);
                }

                if (config('transaction.reversal.require_maker_checker', true)
                    && $reversal->requested_by === $approvedBy) {
                    throw ReversalNotApprovableException::makerCheckerViolation();
                }

                $reversal->update([
                    'status'      => ReversalStatus::Approved->value,
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                ]);

                $this->appendAuditLog($reversalId, $reversal->transaction_id, 'reversal.approved', $approvedBy, 'admin');

                $this->idempotency->recordResponse($lock, 200, ['reversal_id' => $reversalId, 'status' => 'approved']);

                Log::info('transaction.reversal.approved', [
                    'reversal_id'    => $reversalId,
                    'transaction_id' => $reversal->transaction_id,
                    'approved_by'    => $approvedBy,
                ]);

                $reversal->refresh();
                return $reversal;
            });
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    public function reject(
        string $reversalId,
        string $rejectedBy,
        string $reason,
        string $correlationId,
    ): ReversalModel {
        return DB::connection('platform')->transaction(function () use (
            $reversalId, $rejectedBy, $reason
        ): ReversalModel {
            $reversal = ReversalModel::lockForUpdate()->find($reversalId);

            if ($reversal === null) {
                throw ReversalNotFoundException::forId($reversalId);
            }

            if (! in_array($reversal->status->value, [
                ReversalStatus::PendingApproval->value,
                ReversalStatus::Approved->value,
            ], true)) {
                throw new \DomainException("Reversal [{$reversalId}] cannot be rejected from status [{$reversal->status->value}].");
            }

            $reversal->update([
                'status'          => ReversalStatus::Rejected->value,
                'rejected_by'     => $rejectedBy,
                'rejected_reason' => $reason,
            ]);

            $this->appendAuditLog($reversalId, $reversal->transaction_id, 'reversal.rejected', $rejectedBy, 'admin', [
                'reason' => $reason,
            ]);

            Log::info('transaction.reversal.rejected', [
                'reversal_id'    => $reversalId,
                'transaction_id' => $reversal->transaction_id,
                'rejected_by'    => $rejectedBy,
            ]);

            $reversal->refresh();
                return $reversal;
        });
    }

    public function execute(
        string $reversalId,
        string $executedBy,
        string $correlationId,
    ): ReversalModel {
        return DB::connection('platform')->transaction(function () use (
            $reversalId, $executedBy, $correlationId
        ): ReversalModel {
            $reversal = ReversalModel::lockForUpdate()->find($reversalId);

            if ($reversal === null) {
                throw ReversalNotFoundException::forId($reversalId);
            }

            if ($reversal->status !== ReversalStatus::Approved) {
                throw new \DomainException("Reversal [{$reversalId}] must be approved before execution.");
            }

            $tx = $this->transactionRepo->findByIdForUpdate($reversal->transaction_id);

            if ($tx === null) {
                throw TransactionNotFoundException::forId($reversal->transaction_id);
            }

            $template = $tx->metadata['reversal_journal_template'] ?? null;

            if ($template === null) {
                throw new \RuntimeException("Transaction [{$tx->id}] has no reversal_journal_template in metadata.");
            }

            try {
                $journalId = $this->ledger->postEntry([
                    'transaction_reference' => "REVERSAL-{$reversal->id}",
                    'description'           => "Reversal of transaction {$tx->reference}",
                    'correlation_id'        => $correlationId,
                    'lines'                 => [
                        [
                            'type'                   => 'DEBIT',
                            'ledger_account_reference' => $template['credit_account'] ?? 'system-suspense',
                            'amount'                  => $template['amount'],
                            'currency_code'           => $template['currency_code'],
                        ],
                        [
                            'type'                   => 'CREDIT',
                            'ledger_account_reference' => $template['debit_account'] ?? 'system-suspense',
                            'amount'                  => $template['amount'],
                            'currency_code'           => $template['currency_code'],
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                $reversal->update(['status' => ReversalStatus::Failed->value]);
                $this->appendAuditLog($reversalId, $reversal->transaction_id, 'reversal.failed', $executedBy, 'system', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $reversal->update([
                'status'                  => ReversalStatus::Executed->value,
                'executed_at'             => now(),
                'compensating_journal_id' => $journalId,
            ]);

            $this->projectionService->appendStatusHistory(
                transactionId: $tx->id,
                previousStatus: $tx->status,
                newStatus: 'reversed',
                actorId: $executedBy,
                actorType: 'system',
                reason: "Reversal {$reversalId} executed",
                occurredAt: now()->toIso8601String(),
            );

            $tx->update(['status' => 'reversed']);

            $this->appendAuditLog($reversalId, $reversal->transaction_id, 'reversal.executed', $executedBy, 'system', [
                'compensating_journal_id' => $journalId,
            ]);

            Log::info('transaction.reversal.executed', [
                'reversal_id'             => $reversalId,
                'transaction_id'          => $reversal->transaction_id,
                'compensating_journal_id' => $journalId,
                'amount'                  => $template['amount'],
                'currency_code'           => $template['currency_code'],
            ]);

            $reversal->refresh();
                return $reversal;
        });
    }

    private function appendAuditLog(
        string $reversalId,
        string $transactionId,
        string $action,
        ?string $actorId,
        ?string $actorType,
        ?array $metadata = null,
    ): void {
        ReversalAuditLogModel::create([
            'id'             => (string) Uuid::uuid7(),
            'reversal_id'    => $reversalId,
            'transaction_id' => $transactionId,
            'action'         => $action,
            'actor_id'       => $actorId,
            'actor_type'     => $actorType,
            'metadata'       => $metadata,
            'occurred_at'    => now(),
        ]);
    }
}
