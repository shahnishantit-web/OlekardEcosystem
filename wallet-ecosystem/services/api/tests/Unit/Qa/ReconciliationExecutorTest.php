<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\ReconciliationExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ReconciliationExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_executor_writes_results_for_all_active_cases(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Reconciliation->value)
            ->where('is_active', true)
            ->count();

        $run = $this->createTestRun();
        (new ReconciliationExecutor())->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount, 'ReconciliationExecutor must write one result per active test case');
    }

    public function test_no_reconciliation_records_committed_after_execute(): void
    {
        $reconCountBefore = DB::connection('platform')
            ->table('reconciliation.reconciliation_records')
            ->count();

        $run = $this->createTestRun();
        (new ReconciliationExecutor())->execute($run);

        $reconCountAfter = DB::connection('platform')
            ->table('reconciliation.reconciliation_records')
            ->count();

        $this->assertEquals(
            $reconCountBefore,
            $reconCountAfter,
            'ReconciliationExecutor must not commit reconciliation_records — all mutations in inRolledBackTransaction must roll back',
        );
    }

    public function test_executor_does_not_throw(): void
    {
        $run = $this->createTestRun();
        $this->expectNotToPerformAssertions();
        (new ReconciliationExecutor())->execute($run);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::Reconciliation->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'recon-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
