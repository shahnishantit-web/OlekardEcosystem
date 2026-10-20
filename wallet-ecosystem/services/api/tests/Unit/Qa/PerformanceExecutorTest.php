<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\PerformanceExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PerformanceExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_runs_and_stores_evidence_with_latency_percentiles(): void
    {
        config(['qa.thresholds.performance_p95_ms' => 10000]); // lenient threshold for CI

        $run = $this->createTestRun();
        $executor = new PerformanceExecutor();
        $executor->execute($run);

        $results = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->get();

        $this->assertGreaterThan(0, $results->count(), 'Performance executor must produce at least one result');

        // Evidence for at least one result should contain p95
        $hasP95 = $results->contains(function ($row) {
            if (empty($row->evidence)) return false;
            $evidence = json_decode($row->evidence, true);
            return is_array($evidence) && array_key_exists('p95', $evidence);
        });

        $this->assertTrue($hasP95, 'At least one result must have p95 in evidence JSON');
    }

    public function test_executor_fails_outcome_when_p95_threshold_exceeded(): void
    {
        config(['qa.thresholds.performance_p95_ms' => 0]); // impossible threshold → should fail

        $run = $this->createTestRun();
        $executor = new PerformanceExecutor();
        $executor->execute($run);

        $failedCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->where('outcome', TestResultOutcome::Failed->value)
            ->count();

        $this->assertGreaterThan(0, $failedCount, 'At least one result must fail when p95 threshold is 0ms');
    }

    public function test_executor_does_not_throw(): void
    {
        config(['qa.thresholds.performance_p95_ms' => 60000]);

        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new PerformanceExecutor())->execute($run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::Performance->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'perf-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
