<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\DuplicateTransactionExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class DuplicateTransactionExecutorTest extends TestCase
{
    use DatabaseTransactions;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->createTestRun(QaCategory::DuplicateTransaction);
    }

    public function test_executor_writes_results(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::DuplicateTransaction->value)
            ->where('is_active', true)
            ->count();

        $executor = new DuplicateTransactionExecutor();
        $executor->execute($this->run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount);
    }

    public function test_no_ledger_entries_committed_after_run(): void
    {
        $entryCountBefore = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->count();

        $executor = new DuplicateTransactionExecutor();
        $executor->execute($this->run);

        $entryCountAfter = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->count();

        $this->assertEquals(
            $entryCountBefore,
            $entryCountAfter,
            'No ledger entries must be committed during duplicate transaction testing',
        );
    }

    public function test_idempotency_result_is_not_failed(): void
    {
        $executor = new DuplicateTransactionExecutor();
        $executor->execute($this->run);

        $idempotencyCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::DuplicateTransaction->value)
            ->where('scenario_type', 'idempotency')
            ->value('id');

        if ($idempotencyCaseId === null) {
            $this->markTestSkipped('No idempotency test case seeded for duplicate_transaction');
        }

        $outcome = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->where('test_case_id', $idempotencyCaseId)
            ->value('outcome');

        $this->assertNotEquals(TestResultOutcome::Failed->value, $outcome);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(QaCategory $category): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => $category->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'dup-txn-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
