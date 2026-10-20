<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Console\Commands;

use App\Modules\Ledger\Domain\Events\LedgerImbalanceDetectedEvent;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class CheckLedgerBalanceCommand extends Command
{
    protected $signature   = 'ledger:check-balance';
    protected $description = 'Verify per-currency ledger balance integrity (SUM debits == SUM credits).';

    public function __construct(
        private readonly EventPublisherInterface $outboxPublisher,
        private readonly AuditServiceInterface $auditService,
        private readonly MetricsCollectorInterface $metrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $correlationId = (string) Uuid::uuid7();
        $runId         = (string) Uuid::uuid7();
        $detectedAt    = now()->toIso8601String();

        $rows = DB::connection('platform')->select(
            "SELECT
                jl.currency_code,
                COALESCE(SUM(CASE WHEN jl.direction = 'debit'  THEN jl.amount ELSE 0 END), 0) AS debit_total,
                COALESCE(SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END), 0) AS credit_total
             FROM ledger.journal_lines jl
             JOIN ledger.journals j ON j.id = jl.journal_id
             WHERE j.status = 'posted'
             GROUP BY jl.currency_code"
        );

        $imbalances = 0;

        foreach ($rows as $row) {
            $debit    = (int) $row->debit_total;
            $credit   = (int) $row->credit_total;
            $currency = $row->currency_code;

            if ($debit === $credit) {
                continue;
            }

            $discrepancy = abs($debit - $credit);
            $imbalances++;

            Log::critical('Ledger imbalance detected', [
                'currency_code' => $currency,
                'debit_total'   => $debit,
                'credit_total'  => $credit,
                'discrepancy'   => $discrepancy,
                'correlation_id' => $correlationId,
            ]);

            DB::connection('platform')->transaction(function () use (
                $runId, $currency, $debit, $credit, $discrepancy, $detectedAt, $correlationId
            ): void {
                $this->outboxPublisher->publish(new LedgerImbalanceDetectedEvent(
                    commandRunId:  $runId,
                    currencyCode:  $currency,
                    debitTotal:    $debit,
                    creditTotal:   $credit,
                    discrepancy:   $discrepancy,
                    detectedAt:    $detectedAt,
                    correlationId: $correlationId,
                ));

                $this->auditService->record(new AuditEventData(
                    action:        'ledger.imbalance.detected',
                    actorType:     'SYSTEM',
                    resourceType:  'ledger_balance',
                    correlationId: $correlationId,
                    outcome:       'failure',
                    actorId:       null,
                    resourceId:    $runId,
                    afterState:    [
                        'currency_code' => $currency,
                        'debit_total'   => $debit,
                        'credit_total'  => $credit,
                        'discrepancy'   => $discrepancy,
                        'severity'      => 'CRITICAL',
                    ],
                ));
            });

            $this->error("[CRITICAL] Ledger imbalance for {$currency}: debit={$debit}, credit={$credit}, discrepancy={$discrepancy}");
            $this->metrics->increment('ledger.imbalance.detected', ['currency_code' => $currency]);
        }

        if ($imbalances === 0) {
            $this->info('Ledger balance check passed — all currencies balanced.');
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
