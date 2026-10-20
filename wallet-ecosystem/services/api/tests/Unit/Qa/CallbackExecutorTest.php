<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\CallbackExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class CallbackExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_writes_results_for_all_active_cases(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Callback->value)
            ->where('is_active', true)
            ->count();

        $run = $this->createTestRun();
        (new CallbackExecutor())->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount, 'CallbackExecutor must write one result per active test case');
    }

    public function test_no_funding_transactions_committed_after_execute(): void
    {
        $fundingCountBefore = DB::connection('platform')
            ->table('funding.funding_transactions')
            ->count();

        $run = $this->createTestRun();
        (new CallbackExecutor())->execute($run);

        $fundingCountAfter = DB::connection('platform')
            ->table('funding.funding_transactions')
            ->count();

        $this->assertEquals(
            $fundingCountBefore,
            $fundingCountAfter,
            'CallbackExecutor must not commit new funding_transactions — callback simulation must not leave committed state',
        );
    }

    public function test_results_have_valid_outcomes(): void
    {
        $run = $this->createTestRun();
        (new CallbackExecutor())->execute($run);

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

        $this->assertEquals(0, $invalidCount, 'All callback results must have a valid outcome');
    }

    public function test_executor_does_not_throw(): void
    {
        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new CallbackExecutor())->execute($run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::Callback->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'callback-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
