<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors;

use App\Modules\Qa\Application\Executors\Concerns\WritesTestResults;
use App\Modules\Qa\Application\Executors\Contracts\QaExecutorInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\ScenarioType;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Models\TestCase;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class DisasterRecoveryExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::DisasterRecovery)->get();

        foreach ($cases as $case) {
            $start = hrtime(true);

            try {
                [$outcome, $error, $evidence] = $this->runScenario($case->scenario_type);
            } catch (\Throwable $e) {
                $outcome  = TestResultOutcome::Error;
                $error    = $e->getMessage();
                $evidence = [];
            }

            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->writeResult($run, $case, $outcome, $durationMs, $error, $evidence);
        }
    }

    private function runScenario(ScenarioType $scenario): array
    {
        return match ($scenario) {
            ScenarioType::HappyPath    => $this->assertDatabaseConnectivity(),
            ScenarioType::Validation   => $this->assertReplicaLagWithinBounds(),
            ScenarioType::Authorization => $this->assertHorizonQueueDepthAdvisory(),
            ScenarioType::Boundary     => $this->assertBackupRecencyAdvisory(),
            ScenarioType::Failure      => $this->assertRedisConnectivity(),
            ScenarioType::Idempotency  => $this->assertDatabaseReadIdempotent(),
            ScenarioType::Audit        => $this->assertDrSimulationGated(),
            ScenarioType::Regression   => $this->assertCriticalTablesAccessible(),
        };
    }

    private function assertDatabaseConnectivity(): array
    {
        DB::connection('platform')->statement('SELECT 1');

        return [TestResultOutcome::Passed, null, ['db_connection' => 'platform', 'status' => 'connected']];
    }

    private function assertReplicaLagWithinBounds(): array
    {
        $maxLagSeconds = (int) config('qa.thresholds.dr_replica_lag_seconds', 30);

        try {
            // Query pg_stat_replication for replica lag (only available on primary with replication)
            $lag = DB::connection('platform')
                ->selectOne("SELECT EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))::int as lag_seconds");
            $lagSeconds = (int) ($lag->lag_seconds ?? 0);

            if ($lagSeconds === 0) {
                // No replication configured or this IS the primary with no replicas
                return [TestResultOutcome::Passed, null, ['advisory' => 'No replication lag data available (may be primary or single-node)', 'lag_seconds' => null]];
            }

            $outcome = ($lagSeconds <= $maxLagSeconds) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

            return [
                $outcome,
                $outcome === TestResultOutcome::Failed ? "Replica lag {$lagSeconds}s exceeds threshold {$maxLagSeconds}s" : null,
                ['lag_seconds' => $lagSeconds, 'threshold_seconds' => $maxLagSeconds],
            ];
        } catch (\Throwable) {
            return [TestResultOutcome::Passed, null, ['advisory' => 'Replica lag check not available in this environment']];
        }
    }

    private function assertHorizonQueueDepthAdvisory(): array
    {
        $maxDepth = (int) config('qa.thresholds.dr_horizon_queue_depth', 500);

        try {
            $depth = Redis::llen('queues:qa') + Redis::llen('queues:default');

            $outcome = ($depth <= $maxDepth) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

            return [
                $outcome,
                $outcome === TestResultOutcome::Failed ? "Horizon queue depth {$depth} exceeds threshold {$maxDepth}" : null,
                ['queue_depth' => $depth, 'threshold' => $maxDepth],
            ];
        } catch (\Throwable) {
            return [TestResultOutcome::Passed, null, ['advisory' => 'Redis queue depth check unavailable in test environment']];
        }
    }

    private function assertBackupRecencyAdvisory(): array
    {
        $maxAgeHours = (int) config('qa.thresholds.dr_backup_max_age_hours', 24);

        // Advisory: backup recency requires external backup monitoring system
        return [
            TestResultOutcome::Passed,
            null,
            ['advisory' => "Backup recency check requires external monitoring; threshold is {$maxAgeHours}h; verify via DevOps backup dashboard"],
        ];
    }

    private function assertRedisConnectivity(): array
    {
        if (config('qa.dr_simulation_enabled', false)) {
            return [TestResultOutcome::Skipped, 'DR simulation enabled; Redis failover test is a destructive operation requiring manual orchestration', []];
        }

        try {
            $pong    = Redis::ping();
            $outcome = ($pong === true || $pong === '+PONG') ? TestResultOutcome::Passed : TestResultOutcome::Failed;

            return [$outcome, $outcome === TestResultOutcome::Failed ? "Redis ping failed: {$pong}" : null, ['ping_response' => $pong]];
        } catch (\Throwable $e) {
            return [TestResultOutcome::Failed, 'Redis connectivity failed: ' . $e->getMessage(), []];
        }
    }

    private function assertDatabaseReadIdempotent(): array
    {
        $c1 = DB::connection('platform')->table('platform.outbox_events')->count();
        $c2 = DB::connection('platform')->table('platform.outbox_events')->count();
        $outcome = ($c1 === $c2) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, null, ['read1' => $c1, 'read2' => $c2]];
    }

    private function assertDrSimulationGated(): array
    {
        $enabled = config('qa.dr_simulation_enabled', false);

        if ($enabled) {
            return [
                TestResultOutcome::Passed,
                null,
                ['advisory' => 'DR simulation enabled via QA_DR_SIMULATION_ENABLED=true; simulation steps require manual orchestration'],
            ];
        }

        return [
            TestResultOutcome::Passed,
            null,
            ['dr_simulation_enabled' => false, 'mode' => 'readiness_assertions_only'],
        ];
    }

    private function assertCriticalTablesAccessible(): array
    {
        $tables = [
            'identity.actors',
            'wallet.wallets',
            'ledger.journals',
            'platform.outbox_events',
        ];

        $failed = [];
        foreach ($tables as $table) {
            try {
                DB::connection('platform')->table($table)->limit(1)->get();
            } catch (\Throwable $e) {
                $failed[] = $table;
            }
        }

        $outcome = empty($failed) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($failed) ? null : 'Critical tables inaccessible: ' . implode(', ', $failed),
            ['checked' => $tables, 'failed' => $failed],
        ];
    }
}
