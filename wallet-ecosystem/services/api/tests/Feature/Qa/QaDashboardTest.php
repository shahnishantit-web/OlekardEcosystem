<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QaDashboardTest extends TestCase
{
    public function test_dashboard_returns_18_categories(): void
    {
        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson('/v1/qa/dashboard');

        $response->assertStatus(200);

        $categories = $response->json('data.categories');
        $this->assertCount(18, $categories);

        $response->assertJsonStructure([
            'data' => [
                'summary' => ['total_categories', 'categories_with_run'],
                'categories' => [['category', 'label', 'latest_run_id', 'latest_status']],
            ],
        ]);
    }

    public function test_untriggered_category_shows_null_latest_status(): void
    {
        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson('/v1/qa/dashboard');

        $response->assertStatus(200);

        $categories = $response->json('data.categories');
        $untriggered = collect($categories)->where('latest_run_id', null);

        $this->assertGreaterThan(0, $untriggered->count());

        foreach ($untriggered as $cat) {
            $this->assertNull($cat['latest_status']);
        }
    }

    public function test_completed_run_appears_in_category_row(): void
    {
        $category = QaCategory::UnitAuth;
        $runId = (string) Uuid::uuid7();

        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => $runId,
            'category'        => $category->value,
            'status'          => TestRunStatus::Completed->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'qa-dash-test-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
            'completed_at'    => now(),
        ]);

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->getJson('/v1/qa/dashboard');

        $response->assertStatus(200);

        $categories = $response->json('data.categories');
        $row = collect($categories)->firstWhere('category', $category->value);

        $this->assertNotNull($row);
        $this->assertEquals($runId, $row['latest_run_id']);
        $this->assertEquals(TestRunStatus::Completed->value, $row['latest_status']);
    }
}
