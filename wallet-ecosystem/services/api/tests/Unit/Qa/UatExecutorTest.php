<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\UatExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class UatExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_runs_at_least_five_cross_epic_scenarios(): void
    {
        $run = $this->createTestRun();
        $executor = new UatExecutor();
        $executor->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertGreaterThanOrEqual(
            5,
            $resultCount,
            'UAT executor must run at least 5 cross-epic acceptance scenarios',
        );
    }

    public function test_each_scenario_produces_a_test_result(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Uat->value)
            ->where('is_active', true)
            ->count();

        $run = $this->createTestRun();
        $executor = new UatExecutor();
        $executor->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount);
    }

    public function test_executor_does_not_throw(): void
    {
        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new UatExecutor())->execute($run);
    }

    public function test_results_have_valid_outcomes(): void
    {
        $run = $this->createTestRun();
        $executor = new UatExecutor();
        $executor->execute($run);

        $validOutcomes = [
            TestResultOutcome::Passed->value,
            TestResultOutcome::Failed->value,
            TestResultOutcome::Skipped->value,
            TestResultOutcome::Error->value,
        ];

        $invalidCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->whereNotIn('outcome', $validOutcomes)
            ->count();

        $this->assertEquals(0, $invalidCount, 'All UAT results must have a valid outcome');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::Uat->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'uat-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
