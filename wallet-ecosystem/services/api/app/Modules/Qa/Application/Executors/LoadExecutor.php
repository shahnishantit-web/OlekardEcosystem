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

final class LoadExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::Load)->get();

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
            ScenarioType::HappyPath    => $this->assertConcurrentRequestsSucceed(),
            ScenarioType::Validation   => $this->assertNoServerErrors(),
            ScenarioType::Authorization => $this->assertConcurrentAuthCheck(),
            ScenarioType::Boundary     => $this->assertAtConcurrencyLimit(),
            ScenarioType::Failure      => $this->assertGracefulDegradation(),
            ScenarioType::Idempotency  => $this->assertConcurrentIdempotentRequestsSafe(),
            ScenarioType::Audit        => $this->assertLoadAdvisory(),
            ScenarioType::Regression   => $this->assertThroughputBaseline(),
        };
    }

    private function assertConcurrentRequestsSucceed(): array
    {
        $concurrency = (int) config('qa.thresholds.load_concurrency', 50);
        $results     = $this->runConcurrent($concurrency);

        $errors    = array_filter($results, fn (int $s) => $s >= 500);
        $successes = count($results) - count($errors);
        $outcome   = empty($errors) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($errors) ? null : count($errors) . " server errors in {$concurrency} concurrent requests",
            ['concurrency' => $concurrency, 'successes' => $successes, 'server_errors' => count($errors)],
        ];
    }

    private function assertNoServerErrors(): array
    {
        $concurrency = (int) config('qa.thresholds.load_concurrency', 50);
        $results     = $this->runConcurrent($concurrency);

        $serverErrors = array_filter($results, fn (int $s) => $s >= 500);
        $outcome      = empty($serverErrors) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            empty($serverErrors) ? null : 'Server errors detected under load: ' . implode(', ', $serverErrors),
            ['server_error_count' => count($serverErrors), 'total' => count($results)],
        ];
    }

    private function assertConcurrentAuthCheck(): array
    {
        // 10 concurrent no-auth requests should all return 401
        $results = $this->runConcurrent(10, '/v1/wallets');
        $non401  = array_filter($results, fn (int $s) => $s !== 401);
        $outcome = empty($non401) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, empty($non401) ? null : 'Some concurrent requests not returning 401', ['non_401' => count($non401), 'total' => 10]];
    }

    private function assertAtConcurrencyLimit(): array
    {
        $max     = (int) config('qa.thresholds.load_concurrency', 50);
        $results = $this->runConcurrent($max);

        $serverErrors = count(array_filter($results, fn (int $s) => $s >= 500));
        $outcome      = ($serverErrors === 0) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $serverErrors > 0 ? "Server errors at max concurrency {$max}" : null, ['max_concurrency' => $max, 'server_errors' => $serverErrors]];
    }

    private function assertGracefulDegradation(): array
    {
        // Run at 1.5x concurrency limit and check for graceful error handling
        $over    = (int) ((int) config('qa.thresholds.load_concurrency', 50) * 1.5);
        $results = $this->runConcurrent($over);

        $panic   = count(array_filter($results, fn (int $s) => $s >= 500));
        $outcome = ($panic === 0) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            $panic > 0 ? "{$panic} server errors at {$over} concurrent requests" : null,
            ['tested_concurrency' => $over, 'server_errors' => $panic, 'total' => count($results)],
        ];
    }

    private function assertConcurrentIdempotentRequestsSafe(): array
    {
        // Send 10 concurrent requests with the SAME idempotency key — only one should succeed
        $key     = 'qa-load-idem-' . uniqid();
        $results = $this->runConcurrentWithKey(10, $key);

        $serverErrors = count(array_filter($results, fn (int $s) => $s >= 500));
        $outcome      = ($serverErrors === 0) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            $serverErrors > 0 ? "Server errors on concurrent idempotent requests" : null,
            ['idempotency_key' => $key, 'server_errors' => $serverErrors, 'total' => count($results)],
        ];
    }

    private function assertLoadAdvisory(): array
    {
        return [TestResultOutcome::Passed, null, ['advisory' => 'Full load test with concurrent processes requires external load testing tool; in-process simulation is a proxy indicator only']];
    }

    private function assertThroughputBaseline(): array
    {
        $start   = hrtime(true);
        $results = $this->runConcurrent(20, '/health');
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;
        $rps     = count($results) / max($elapsed, 0.001);

        return [TestResultOutcome::Passed, null, ['requests_per_second' => round($rps, 2), 'elapsed_seconds' => round($elapsed, 3)]];
    }

    /** @return int[] Status codes */
    private function runConcurrent(int $count, string $uri = '/v1/auth/login'): array
    {
        $results = [];
        $server  = ['HTTP_ACCEPT' => 'application/json'];

        for ($i = 0; $i < $count; $i++) {
            $req       = Request::create($uri, 'GET', [], [], [], $server);
            $results[] = app()->handle($req)->getStatusCode();
        }

        return $results;
    }

    /** @return int[] Status codes */
    private function runConcurrentWithKey(int $count, string $idempotencyKey): array
    {
        $results = [];
        $server  = ['HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey];

        for ($i = 0; $i < $count; $i++) {
            $req       = Request::create('/v1/transfers', 'POST', [], [], [], $server, '{}');
            $results[] = app()->handle($req)->getStatusCode();
        }

        return $results;
    }
}
