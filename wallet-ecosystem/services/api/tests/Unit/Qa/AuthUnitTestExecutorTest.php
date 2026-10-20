<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\AuthUnitTestExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\ScenarioType;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AuthUnitTestExecutorTest extends TestCase
{
    use DatabaseTransactions;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->createTestRun(QaCategory::UnitAuth);
    }

    public function test_executor_writes_result_for_each_active_test_case(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::UnitAuth->value)
            ->where('is_active', true)
            ->count();

        $executor = new AuthUnitTestExecutor();
        $executor->execute($this->run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->count();

        $this->assertEquals($caseCount, $resultCount);
    }

    public function test_happy_path_result_is_passed(): void
    {
        $executor = new AuthUnitTestExecutor();
        $executor->execute($this->run);

        $happyCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::UnitAuth->value)
            ->where('scenario_type', ScenarioType::HappyPath->value)
            ->value('id');

        if ($happyCaseId === null) {
            $this->markTestSkipped('No happy_path test case seeded for unit_auth');
        }

        $outcome = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->where('test_case_id', $happyCaseId)
            ->value('outcome');

        $this->assertEquals(TestResultOutcome::Passed->value, $outcome);
    }

    public function test_audit_scenario_result_is_present(): void
    {
        $executor = new AuthUnitTestExecutor();
        $executor->execute($this->run);

        $auditCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::UnitAuth->value)
            ->where('scenario_type', ScenarioType::Audit->value)
            ->value('id');

        if ($auditCaseId === null) {
            $this->markTestSkipped('No audit test case seeded for unit_auth');
        }

        $exists = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->where('test_case_id', $auditCaseId)
            ->exists();

        $this->assertTrue($exists);
    }

    public function test_executor_does_not_throw(): void
    {
        $executor = new AuthUnitTestExecutor();

        $this->expectNotToPerformAssertions();
        $executor->execute($this->run);
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
            'idempotency_key' => 'unit-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
