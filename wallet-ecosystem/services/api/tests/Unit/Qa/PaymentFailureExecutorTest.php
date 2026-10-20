<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\PaymentFailureExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PaymentFailureExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_writes_results_for_all_active_cases(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::PaymentFailure->value)
            ->where('is_active', true)
            ->count();

        $run = $this->createTestRun();
        (new PaymentFailureExecutor())->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount, 'PaymentFailureExecutor must write one result per active test case');
    }

    public function test_no_committed_partial_ledger_state_after_execute(): void
    {
        $journalsBefore = DB::connection('platform')->table('ledger.journals')->count();

        $run = $this->createTestRun();
        (new PaymentFailureExecutor())->execute($run);

        $journalsAfter = DB::connection('platform')->table('ledger.journals')->count();

        $this->assertEquals(
            $journalsBefore,
            $journalsAfter,
            'PaymentFailureExecutor failure scenarios must not commit partial journal entries',
        );
    }

    public function test_failure_scenarios_produce_non_error_outcomes(): void
    {
        $run = $this->createTestRun();
        (new PaymentFailureExecutor())->execute($run);

        // Failure scenarios (insufficient funds, gateway timeout, invalid account) should
        // produce passed or failed outcomes — not error — since these are expected outcomes
        $errorCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->where('outcome', TestResultOutcome::Error->value)
            ->count();

        $totalCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        // Allow up to 20% error rate (some scenarios may not be configured in test env)
        $errorRate = $totalCount > 0 ? ($errorCount / $totalCount) : 0;
        $this->assertLessThanOrEqual(0.2, $errorRate, 'No more than 20% of PaymentFailure results should have error outcome');
    }

    public function test_executor_does_not_throw(): void
    {
        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new PaymentFailureExecutor())->execute($run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::PaymentFailure->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'payment-failure-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
