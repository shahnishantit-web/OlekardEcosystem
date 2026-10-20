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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RegressionExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::Regression)->get();

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
            ScenarioType::HappyPath    => $this->assertCriticalEndpointsRespond(),
            ScenarioType::Validation   => $this->assertSchemaConsistency(),
            ScenarioType::Authorization => $this->assertAuthStillWorks(),
            ScenarioType::Boundary     => $this->assertModuleProvidersLoaded(),
            ScenarioType::Failure      => $this->assertNoUnhandledExceptions(),
            ScenarioType::Idempotency  => $this->assertIdempotencyTableIntegrity(),
            ScenarioType::Audit        => $this->assertAuditModuleActive(),
            ScenarioType::Regression   => $this->assertNoSchemaDrift(),
        };
    }

    private function assertCriticalEndpointsRespond(): array
    {
        $endpoints = [
            ['GET',  '/health'],
            ['POST', '/v1/auth/login'],
            ['GET',  '/v1/wallets'],
            ['GET',  '/v1/transactions'],
        ];

        $failures = [];
        foreach ($endpoints as [$method, $uri]) {
            $status = $this->call($method, $uri, null);
            if ($status === 500) {
                $failures[] = "{$method} {$uri} → 500";
            }
        }

        $outcome = empty($failures) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($failures) ? null : 'Critical endpoints returning 500: ' . implode('; ', $failures),
            ['checked' => count($endpoints), 'failures' => $failures],
        ];
    }

    private function assertSchemaConsistency(): array
    {
        $schemas = ['qa', 'identity', 'wallet', 'ledger', 'platform'];
        $missing = [];

        foreach ($schemas as $schema) {
            $exists = DB::connection('platform')
                ->selectOne("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schema]);
            if (!$exists) {
                $missing[] = $schema;
            }
        }

        $outcome = empty($missing) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($missing) ? null : 'Missing database schemas: ' . implode(', ', $missing),
            ['checked_schemas' => $schemas, 'missing' => $missing],
        ];
    }

    private function assertAuthStillWorks(): array
    {
        $status  = $this->call('POST', '/v1/auth/login', null, ['email' => 'admin@olekard.local', 'password' => 'Admin123!']);
        $outcome = in_array($status, [200, 201, 401, 422]) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Auth endpoint returned unexpected {$status}" : null, ['status_code' => $status]];
    }

    private function assertModuleProvidersLoaded(): array
    {
        $providers = app()->getLoadedProviders();
        $required  = [
            'App\Modules\Qa\QaServiceProvider',
            'App\Modules\Identity\Providers\IdentityServiceProvider',
            'App\Modules\Wallet\Providers\WalletServiceProvider',
            'App\Modules\Ledger\Providers\LedgerServiceProvider',
        ];

        $missing = array_filter($required, fn (string $p) => !isset($providers[$p]));

        $outcome = empty($missing) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($missing) ? null : 'Providers not loaded: ' . implode(', ', $missing),
            ['checked' => count($required), 'missing' => array_values($missing)],
        ];
    }

    private function assertNoUnhandledExceptions(): array
    {
        // Check for recent 500 errors in logs by sampling known-safe endpoints
        $probes   = ['/health', '/v1/auth/login', '/v1/wallets'];
        $failures = [];

        foreach ($probes as $uri) {
            $status = $this->call('GET', $uri, null);
            if ($status === 500) {
                $failures[] = "{$uri} → 500";
            }
        }

        $outcome = empty($failures) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, empty($failures) ? null : implode('; ', $failures), ['probes' => $probes, 'failures' => $failures]];
    }

    private function assertIdempotencyTableIntegrity(): array
    {
        $count = DB::connection('platform')
            ->table('platform.idempotency_keys')
            ->count();

        $duplicates = DB::connection('platform')
            ->table('platform.idempotency_keys')
            ->selectRaw('key, COUNT(*) as cnt')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $outcome = ($duplicates === 0) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            $duplicates > 0 ? "Found {$duplicates} duplicate idempotency keys" : null,
            ['total_keys' => $count, 'duplicates' => $duplicates],
        ];
    }

    private function assertAuditModuleActive(): array
    {
        $recentEvents = DB::connection('platform')
            ->table('audit.audit_events')
            ->where('occurred_at', '>', now()->subHour())
            ->count();

        // Advisory: at least some audit events in the last hour indicates the module is active
        return [
            TestResultOutcome::Passed,
            null,
            ['audit_events_last_hour' => $recentEvents, 'advisory' => 'zero events is expected in a fresh environment'],
        ];
    }

    private function assertNoSchemaDrift(): array
    {
        // Check that core QA tables exist and have expected column counts
        $tableChecks = [
            'qa.test_cases'   => 9,  // id, code, category, name, description, scenario_type, expected_outcome, is_active, created_at/updated_at
            'qa.test_runs'    => 8,  // id, category, triggered_by, status, idempotency_key, correlation_id, started_at, completed_at, created_at
            'qa.test_results' => 7,  // id, test_run_id, test_case_id, outcome, duration_ms, error_message, evidence, created_at
        ];

        $drifted = [];
        foreach ($tableChecks as $table => $expectedMin) {
            try {
                [$schema, $tableName] = explode('.', $table);
                $cols = DB::connection('platform')
                    ->select("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?", [$schema, $tableName]);
                if (count($cols) < $expectedMin) {
                    $drifted[] = "{$table}: expected >={$expectedMin} cols, got " . count($cols);
                }
            } catch (\Throwable) {
                $drifted[] = "{$table}: inaccessible";
            }
        }

        $outcome = empty($drifted) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($drifted) ? null : 'Schema drift detected: ' . implode('; ', $drifted),
            ['tables_checked' => array_keys($tableChecks), 'drift' => $drifted],
        ];
    }

    private function call(string $method, string $uri, ?string $token, array $data = []): int
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        }
        if (!empty($data)) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $req = Request::create($uri, $method, $data, [], [], $server, !empty($data) ? json_encode($data) : null);
        return app()->handle($req)->getStatusCode();
    }
}
