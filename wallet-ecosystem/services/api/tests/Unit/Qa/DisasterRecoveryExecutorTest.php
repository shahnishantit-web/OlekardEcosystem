<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\DisasterRecoveryExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class DisasterRecoveryExecutorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_readiness_assertions_run_when_dr_simulation_disabled(): void
    {
        config(['qa.dr_simulation_enabled' => false]);

        $run = $this->createTestRun();
        $executor = new DisasterRecoveryExecutor();
        $executor->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertGreaterThan(0, $resultCount, 'Readiness checks must produce results when simulation is disabled');
    }

    public function test_simulation_steps_noted_when_dr_simulation_enabled(): void
    {
        config(['qa.dr_simulation_enabled' => true]);

        $run = $this->createTestRun();
        $executor = new DisasterRecoveryExecutor();
        $executor->execute($run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $run->id)
            ->count();

        $this->assertGreaterThan(0, $resultCount, 'Simulation-enabled mode must still produce result records');
    }

    public function test_executor_does_not_throw_in_either_mode(): void
    {
        $this->expectNotToPerformAssertions();

        config(['qa.dr_simulation_enabled' => false]);
        $run1 = $this->createTestRun();
        (new DisasterRecoveryExecutor())->execute($run1);

        config(['qa.dr_simulation_enabled' => true]);
        $run2 = $this->createTestRun();
        (new DisasterRecoveryExecutor())->execute($run2);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTestRun(): TestRun
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => QaCategory::DisasterRecovery->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'dr-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
