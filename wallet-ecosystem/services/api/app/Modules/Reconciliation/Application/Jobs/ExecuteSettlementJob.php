<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Jobs;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reconciliation\Domain\Adapters\SettlementAdapterInterface;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use App\Modules\Reconciliation\Domain\Enums\SettlementStatus;
use App\Modules\Reconciliation\Domain\Models\SettlementBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ExecuteSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        public readonly string $settlementId,
        public readonly string $executorId,
    ) {
        $this->onQueue('reconciliation');
    }

    public function handle(
        SettlementAdapterInterface $adapter,
        LedgerModuleContract $ledger,
        AuditServiceInterface $audit,
        ReconciliationMetricsInterface $metrics,
    ): void {
        Log::info('reconciliation.job.execute_settlement.started', ['settlement_id' => $this->settlementId]);

        $settlement = SettlementBatch::on('reconciliation')->find($this->settlementId);

        if ($settlement === null) {
            Log::warning('reconciliation.job.execute_settlement.not_found', ['settlement_id' => $this->settlementId]);
            return;
        }

        if ($settlement->status !== SettlementStatus::Executing) {
            Log::warning('reconciliation.job.execute_settlement.wrong_status', [
                'settlement_id' => $this->settlementId,
                'status'        => $settlement->status->value,
            ]);
            return;
        }

        DB::connection('reconciliation')->transaction(function () use ($settlement, $adapter, $ledger, $audit, $metrics): void {
            // Execute via adapter (NullSettlementAdapter by default; swappable via config)
            $externalReference = $adapter->execute($settlement);

            // Post double-entry journal via E07 LedgerModuleContract — entry_type='settlement'
            $journalId = $ledger->postEntry([
                'idempotency_key'       => 'settlement:' . $settlement->id,
                'entry_type'            => 'settlement',
                'currency_code'         => $settlement->currency_code,
                'transaction_reference' => $settlement->id,
                'correlation_id'        => $settlement->id,
                'description'           => "Settlement execution for batch {$settlement->id}",
                'actor_id'              => $this->executorId,
                'lines'                 => [
                    [
                        'ledger_account_reference' => config('ledger.system.suspense_account_ref', ''),
                        'direction'                => 'debit',
                        'amount'                   => (int) bcmul($settlement->net_amount, '100', 0),
                    ],
                    [
                        'ledger_account_reference' => config('ledger.system.revenue_account_ref', ''),
                        'direction'                => 'credit',
                        'amount'                   => (int) bcmul($settlement->net_amount, '100', 0),
                    ],
                ],
            ]);

            // Update settlement in same transaction — if ledger throws, settlement reverts to 'executing'
            $settlement->update([
                'status'               => SettlementStatus::Executed->value,
                'ledger_transaction_id' => $journalId,
                'external_reference'   => $externalReference,
                'executed_at'          => now(),
            ]);

            $metrics->incrementSettlementAction('executed');

            $audit->record(new AuditEventData(
                action:        'reconciliation.settlement.executed',
                actorType:     'system',
                resourceType:  'settlement_batch',
                correlationId: $this->settlementId,
                outcome:       'success',
                actorId:       $this->executorId,
                resourceId:    $this->settlementId,
                afterState:    [
                    'status'               => SettlementStatus::Executed->value,
                    'ledger_transaction_id' => $journalId,
                    'external_reference'   => $externalReference,
                ],
            ));

            Log::info('reconciliation.job.execute_settlement.completed', [
                'settlement_id'         => $this->settlementId,
                'ledger_transaction_id' => $journalId,
                'external_reference'    => $externalReference,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('reconciliation.job.execute_settlement.failed', [
            'settlement_id' => $this->settlementId,
            'exception'     => $exception->getMessage(),
        ]);

        $settlement = SettlementBatch::on('reconciliation')->find($this->settlementId);

        if ($settlement !== null) {
            $settlement->update([
                'status'   => SettlementStatus::Failed->value,
                'metadata' => array_merge($settlement->metadata ?? [], [
                    'error'      => $exception->getMessage(),
                    'failed_at'  => now()->toIso8601String(),
                ]),
            ]);
        }
    }
}
