<?php

declare(strict_types=1);

namespace Tests\Unit\Qa;

use App\Modules\Qa\Application\Executors\SecurityExecutor;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SecurityExecutorTest extends TestCase
{
    use DatabaseTransactions;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = $this->createTestRun(QaCategory::Security);
    }

    public function test_executor_writes_owasp_results(): void
    {
        $caseCount = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Security->value)
            ->where('is_active', true)
            ->count();

        $executor = new SecurityExecutor();
        $executor->execute($this->run);

        $resultCount = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->count();

        $this->assertGreaterThan(0, $resultCount, 'Security executor must produce at least one result');
        $this->assertEquals($caseCount, $resultCount);
    }

    public function test_sql_injection_probe_does_not_produce_failed_outcome(): void
    {
        $executor = new SecurityExecutor();
        $executor->execute($this->run);

        // SQL injection probe should return 400/422/not-500 which maps to passed
        $failureCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Security->value)
            ->where('scenario_type', 'failure')
            ->value('id');

        if ($failureCaseId === null) {
            $this->markTestSkipped('No failure test case seeded for security');
        }

        $outcome = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->where('test_case_id', $failureCaseId)
            ->value('outcome');

        // SQL injection probe returning non-500 → passed or skipped, never error from injection
        $this->assertNotEquals(TestResultOutcome::Error->value, $outcome);
    }

    public function test_authorization_probe_reports_result(): void
    {
        $executor = new SecurityExecutor();
        $executor->execute($this->run);

        $authCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', QaCategory::Security->value)
            ->where('scenario_type', 'authorization')
            ->value('id');

        if ($authCaseId === null) {
            $this->markTestSkipped('No authorization test case seeded for security');
        }

        $exists = DB::connection('platform')
            ->table('qa.test_results')
            ->where('test_run_id', $this->run->id)
            ->where('test_case_id', $authCaseId)
            ->exists();

        $this->assertTrue($exists);
    }

    public function test_executor_does_not_throw(): void
    {
        $executor = new SecurityExecutor();
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
            'idempotency_key' => 'security-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'started_at'      => now(),
        ]);

        return TestRun::on('platform')->findOrFail($id);
    }
}
