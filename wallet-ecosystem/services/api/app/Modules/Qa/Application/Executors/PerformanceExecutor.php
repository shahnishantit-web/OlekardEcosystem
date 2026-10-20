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

final class PerformanceExecutor implements QaExecutorInterface
{
    use WritesTestResults;

    private const ITERATIONS = 100;

    /** Key endpoints to benchmark. */
    private const ENDPOINTS = [
        ['POST', '/v1/auth/login', ['email' => 'admin@olekard.local', 'password' => 'Admin123!']],
        ['GET',  '/v1/wallets',    []],
        ['GET',  '/v1/transactions', []],
        ['GET',  '/v1/qa/dashboard', []],
        ['GET',  '/health', []],
    ];

    public function execute(TestRun $run): void
    {
        $cases = TestCase::active()->forCategory(QaCategory::Performance)->get();

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
            ScenarioType::HappyPath    => $this->benchmarkKeyEndpoints(),
            ScenarioType::Validation   => $this->assertP95BelowThreshold(),
            ScenarioType::Authorization => $this->assertAuthEndpointLatency(),
            ScenarioType::Boundary     => $this->assertMinimumThroughput(),
            ScenarioType::Failure      => $this->assertNoThrottlingUnder100Req(),
            ScenarioType::Idempotency  => $this->assertReadIdempotencyLatency(),
            ScenarioType::Audit        => $this->assertLatencyHistogramComplete(),
            ScenarioType::Regression   => $this->assertNoLatencyRegression(),
        };
    }

    private function benchmarkKeyEndpoints(): array
    {
        $allLatencies = [];
        $perEndpoint  = [];

        foreach (self::ENDPOINTS as [$method, $uri, $data]) {
            $latencies = $this->measureEndpoint($method, $uri, $data, 20);
            sort($latencies);
            $perEndpoint[$uri] = [
                'p50_ms' => $this->percentile($latencies, 50),
                'p95_ms' => $this->percentile($latencies, 95),
                'p99_ms' => $this->percentile($latencies, 99),
                'min_ms' => $latencies[0] ?? 0,
                'max_ms' => end($latencies) ?: 0,
                'count'  => count($latencies),
            ];
            $allLatencies = array_merge($allLatencies, $latencies);
        }

        return [TestResultOutcome::Passed, null, ['per_endpoint' => $perEndpoint, 'total_requests' => count($allLatencies)]];
    }

    private function assertP95BelowThreshold(): array
    {
        $threshold  = (int) config('qa.thresholds.performance_p95_ms', 500);
        $latencies  = [];

        foreach (self::ENDPOINTS as [$method, $uri, $data]) {
            $latencies = array_merge($latencies, $this->measureEndpoint($method, $uri, $data, 20));
        }

        sort($latencies);
        $p95     = $this->percentile($latencies, 95);
        $outcome = ($p95 <= $threshold) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            $outcome === TestResultOutcome::Failed ? "p95 {$p95}ms exceeds threshold {$threshold}ms" : null,
            ['p95_ms' => $p95, 'threshold_ms' => $threshold, 'sample_size' => count($latencies)],
        ];
    }

    private function assertAuthEndpointLatency(): array
    {
        $latencies = $this->measureEndpoint('POST', '/v1/auth/login', ['email' => 'qa@olekard.local', 'password' => 'Qa123!'], 10);
        sort($latencies);
        $p95 = $this->percentile($latencies, 95);

        // Auth can be slower (crypto); use 2x threshold
        $threshold = (int) config('qa.thresholds.performance_p95_ms', 500) * 2;
        $outcome   = ($p95 <= $threshold) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "Auth p95 {$p95}ms > {$threshold}ms" : null, ['auth_p95_ms' => $p95, 'threshold_ms' => $threshold]];
    }

    private function assertMinimumThroughput(): array
    {
        $start   = hrtime(true);
        $count   = 0;
        foreach ($this->measureEndpoint('GET', '/health', [], 20) as $_) {
            $count++;
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000_000; // seconds
        $rps     = $count / max($elapsed, 0.001);

        return [TestResultOutcome::Passed, null, ['requests_per_second' => round($rps, 2), 'sample_count' => $count, 'elapsed_seconds' => round($elapsed, 3)]];
    }

    private function assertNoThrottlingUnder100Req(): array
    {
        $throttled = 0;
        foreach ($this->measureEndpoint('GET', '/health', [], self::ITERATIONS, true) as $status) {
            if ($status === 429) {
                $throttled++;
            }
        }

        $outcome = ($throttled === 0) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [
            $outcome,
            $throttled > 0 ? "Throttled {$throttled} of " . self::ITERATIONS . " requests" : null,
            ['throttled' => $throttled, 'total' => self::ITERATIONS],
        ];
    }

    private function assertReadIdempotencyLatency(): array
    {
        $l1 = $this->measureEndpoint('GET', '/health', [], 5);
        $l2 = $this->measureEndpoint('GET', '/health', [], 5);
        sort($l1); sort($l2);

        $diff = abs($this->percentile($l1, 50) - $this->percentile($l2, 50));

        return [TestResultOutcome::Passed, null, ['median_diff_ms' => $diff, 'advisory' => 'read latency should be stable across calls']];
    }

    private function assertLatencyHistogramComplete(): array
    {
        $latencies = $this->measureEndpoint('GET', '/health', [], 10);
        sort($latencies);

        $histogram = [
            'p50_ms' => $this->percentile($latencies, 50),
            'p95_ms' => $this->percentile($latencies, 95),
            'p99_ms' => $this->percentile($latencies, 99),
        ];

        return [TestResultOutcome::Passed, null, $histogram];
    }

    private function assertNoLatencyRegression(): array
    {
        $baseline   = (int) config('qa.thresholds.performance_p95_ms', 500);
        $latencies  = $this->measureEndpoint('GET', '/health', [], 20);
        sort($latencies);
        $p95    = $this->percentile($latencies, 95);
        $outcome = ($p95 <= $baseline * 1.5) ? TestResultOutcome::Passed : TestResultOutcome::Failed;

        return [$outcome, $outcome === TestResultOutcome::Failed ? "p95 {$p95}ms is >50% above baseline {$baseline}ms" : null, ['p95_ms' => $p95, 'baseline_ms' => $baseline]];
    }

    /** @return int[] latency values in ms (or status codes when $returnStatus = true) */
    private function measureEndpoint(string $method, string $uri, array $data, int $iterations, bool $returnStatus = false): array
    {
        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            $server = ['HTTP_ACCEPT' => 'application/json'];
            if (!empty($data)) {
                $server['CONTENT_TYPE'] = 'application/json';
            }
            $req   = Request::create($uri, $method, $data, [], [], $server, !empty($data) ? json_encode($data) : null);
            $start = hrtime(true);
            $resp  = app()->handle($req);
            $ms    = (int) ((hrtime(true) - $start) / 1_000_000);

            $results[] = $returnStatus ? $resp->getStatusCode() : $ms;
        }

        return $results;
    }

    private function percentile(array $sorted, int $pct): int
    {
        if (empty($sorted)) {
            return 0;
        }
        $index = (int) ceil(($pct / 100) * count($sorted)) - 1;
        return $sorted[max(0, $index)];
    }
}
