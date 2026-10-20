<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Models\LedgerIntegritySnapshotModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Hourly scan: every posted journal must have balanced debit/credit lines.
 * Writes a LedgerIntegritySnapshot and emits an audit alert per imbalanced journal (FR-009).
 */
final class LedgerIntegrityJob implements ShouldQueue
{
    use Queueable;
    use HasCorrelationId;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(string $correlationId)
    {
        $this->correlationId = $correlationId;
        $this->onQueue('platform.critical');
    }

    public function handle(AuditService $auditService): void
    {
        $this->injectCorrelationContext();

        $startMs = (int) (microtime(true) * 1000);

        // Journals where sum(debit lines) ≠ sum(credit lines) — a double-entry invariant violation.
        // Excludes journals posted within the last 60 seconds (in-flight window) to avoid false positives.
        $imbalancedJournals = DB::connection('ledger')
            ->select(
                "SELECT j.id AS journal_id, j.transaction_reference,
                        SUM(CASE WHEN jl.direction = 'debit' THEN jl.amount ELSE 0 END)  AS total_debit,
                        SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END) AS total_credit
                 FROM ledger.journals j
                 JOIN ledger.journal_lines jl ON jl.journal_id = j.id
                 WHERE j.posted_at < NOW() - INTERVAL '60 seconds'
                 GROUP BY j.id, j.transaction_reference
                 HAVING SUM(CASE WHEN jl.direction = 'debit' THEN jl.amount ELSE 0 END)
                      != SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END)"
            );

        // Count of distinct ledger accounts checked
        $accountsChecked = (int) DB::connection('ledger')
            ->table('ledger.journal_lines')
            ->distinct()
            ->count('ledger_account_id');

        // Resolve affected wallet owner_references from imbalanced journals
        $affectedWalletIds = [];
        if (count($imbalancedJournals) > 0) {
            $journalIds       = array_column($imbalancedJournals, 'journal_id');
            $affectedAccounts = DB::connection('ledger')
                ->table('ledger.journal_lines AS jl')
                ->join('ledger.ledger_accounts AS la', 'la.id', '=', 'jl.ledger_account_id')
                ->whereIn('jl.journal_id', $journalIds)
                ->whereNotNull('la.owner_reference')
                ->distinct()
                ->pluck('la.owner_reference')
                ->toArray();
            $affectedWalletIds = array_values($affectedAccounts);
        }

        $discrepancyCount = count($imbalancedJournals);
        $outcome          = $discrepancyCount > 0 ? 'discrepancy_found' : 'clean';
        $durationMs       = (int) (microtime(true) * 1000) - $startMs;

        $snapshot = new LedgerIntegritySnapshotModel([
            'id'                  => (string) Uuid::uuid7(),
            'scan_at'             => now(),
            'wallets_checked'     => $accountsChecked,
            'discrepancy_count'   => $discrepancyCount,
            'affected_wallet_ids' => $affectedWalletIds,
            'outcome'             => $outcome,
            'duration_ms'         => $durationMs,
            'correlation_id'      => $this->correlationId,
            'created_at'          => now(),
        ]);
        $snapshot->save();

        Log::info('ledger.integrity.scan.completed', [
            'outcome'           => $outcome,
            'accounts_checked'  => $accountsChecked,
            'discrepancy_count' => $discrepancyCount,
            'duration_ms'       => $durationMs,
        ]);

        // Emit an alert audit event per affected wallet (FR-009)
        foreach ($affectedWalletIds as $walletId) {
            $auditService->record(new AuditEventData(
                action:        'platform.ledger.integrity_failure_detected',
                actorType:     'SYSTEM',
                resourceType:  'wallet',
                correlationId: $this->correlationId,
                outcome:       'failure',
                resourceId:    $walletId,
                afterState:    ['snapshot_id' => $snapshot->id, 'wallet_id' => $walletId],
            ));
        }
    }
}
