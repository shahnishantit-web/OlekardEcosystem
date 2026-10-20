<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QaRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_list_returns_paginated_runs(): void
    {
        $this->seedTestRun(QaCategory::UnitAuth);
        $this->seedTestRun(QaCategory::Security);

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson('/v1/qa/runs');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);

        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
    }

    public function test_list_filtered_by_category(): void
    {
        $this->seedTestRun(QaCategory::UnitAuth);
        $this->seedTestRun(QaCategory::Security);

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson('/v1/qa/runs?category=' . QaCategory::UnitAuth->value);

        $response->assertStatus(200);

        foreach ($response->json('data') as $item) {
            $this->assertEquals(QaCategory::UnitAuth->value, $item['category']);
        }
    }

    public function test_detail_returns_run_with_results(): void
    {
        $runId = $this->seedTestRunWithResult(QaCategory::UnitAuth);

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson("/v1/qa/runs/{$runId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id', 'category', 'status', 'counts',
                    'results' => [['id', 'outcome', 'duration_ms']],
                ],
            ]);
    }

    public function test_detail_returns_404_for_unknown_id(): void
    {
        $fakeId = (string) Uuid::uuid7();

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson("/v1/qa/runs/{$fakeId}");

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'QA_RUN_NOT_FOUND');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedTestRun(QaCategory $category, TestRunStatus $status = TestRunStatus::Completed): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $id,
            'category'        => $category->value,
            'status'          => $status->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'qa-run-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
        ]);

        return $id;
    }

    private function seedTestRunWithResult(QaCategory $category): string
    {
        $runId = $this->seedTestRun($category);

        $testCaseId = DB::connection('platform')
            ->table('qa.test_cases')
            ->where('category', $category->value)
            ->value('id');

        if ($testCaseId) {
            DB::connection('platform')->table('qa.test_results')->insert([
                'id'           => (string) Uuid::uuid7(),
                'test_run_id'  => $runId,
                'test_case_id' => $testCaseId,
                'outcome'      => TestResultOutcome::Passed->value,
                'duration_ms'  => 42,
                'created_at'   => now(),
            ]);
        }

        return $runId;
    }
}
