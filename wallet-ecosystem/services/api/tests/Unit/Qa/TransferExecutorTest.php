<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\TransferExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class TransferExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_writes_results_for_all_active_cases(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Transfer->value)
            ->where('is_active', true)
            ->count();

        $run = $this->createTestRun();
        (new TransferExecutor())->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount, 'TransferExecutor must write one result per active test case');
    }

    public function test_no_ledger_mutations_committed_after_execute(): void
    {
        $journalsBefore = DB::connection('platform')->table('ledger.journals')->count();
        $linesBefore    = DB::connection('platform')->table('ledger.journal_lines')->count();

        $run = $this->createTestRun();
        (new TransferExecutor())->execute($run);

        $journalsAfter = DB::connection('platform')->table('ledger.journals')->count();
        $linesAfter    = DB::connection('platform')->table('ledger.journal_lines')->count();

        $this->assertEquals($journalsBefore, $journalsAfter, 'TransferExecutor must not commit journals');
        $this->assertEquals($linesBefore, $linesAfter, 'TransferExecutor must not commit journal_lines');
    }

    public function test_idempotency_result_is_present(): void
    {
        $run = $this->createTestRun();
        (new TransferExecutor())->execute($run);

        $idempotencyCase = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Transfer->value)
            ->where('scenario_type', 'idempotency')
            ->where('is_active', true)
            ->first();

        if ($idempotencyCase === null) {
            $this->markTestSkipped('No idempotency test case seeded for transfer category');
        }

        $idempotencyResult = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->where('test_case_id', $idempotencyCase->id)
            ->first();

        $this->assertNotNull($idempotencyResult, 'Idempotency scenario must produce a TestResult');
        $this->assertNotEquals(
            TestResultOutcome::Error->value,
            $idempotencyResult->outcome,
            'Idempotency scenario must not produce an error outcome',
        );
    }

    public function test_executor_does_not_throw(): void
    {
        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new TransferExecutor())->execute($run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::Transfer->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'transfer-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
