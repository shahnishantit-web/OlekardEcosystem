<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QaTriggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_trigger_returns_202_for_new_run(): void
    {
        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->withHeaders(['Idempotency-Key' => 'qa-test-001'])
            ->postJson('/v1/qa/unit-test-authentication');

        $response->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'category', 'status']]);

        $this->assertEquals('unit_auth', $response->json('data.category'));
        $this->assertEquals('pending', $response->json('data.status'));
    }

    public function test_trigger_returns_401_without_auth(): void
    {
        $response = $this->postJson('/v1/qa/unit-test-authentication', [], [
            'Idempotency-Key' => 'qa-test-noauth',
        ]);

        $response->assertStatus(401);
    }

    public function test_trigger_returns_403_without_qa_execute_permission(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.dashboard.operational')
            ->withHeaders(['Idempotency-Key' => 'qa-test-noperm'])
            ->postJson('/v1/qa/unit-test-authentication');

        $response->assertStatus(403);
    }

    public function test_trigger_returns_422_when_idempotency_key_missing(): void
    {
        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->postJson('/v1/qa/unit-test-authentication');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'QA_IDEM_REQUIRED');
    }

    public function test_trigger_returns_200_for_duplicate_idempotency_key(): void
    {
        $key = 'qa-idem-dup-' . Uuid::uuid7()->toString();

        $first = $this->actingAsAdminWithPermission('qa.execute')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/qa/unit-test-authentication');

        $first->assertStatus(202);
        $runId = $first->json('data.id');

        $second = $this->actingAsAdminWithPermission('qa.execute')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/qa/unit-test-authentication');

        $second->assertStatus(200)
            ->assertJsonPath('data.id', $runId);
    }

    public function test_trigger_returns_409_when_category_already_running(): void
    {
        // Manually insert a running run to simulate concurrent run
        DB::connection('platform')->table('qa.test_runs')->insert([
            'id'              => (string) Uuid::uuid7(),
            'category'        => QaCategory::UnitAuth->value,
            'status'          => TestRunStatus::Running->value,
            'triggered_by'    => (string) Uuid::uuid7(),
            'idempotency_key' => 'qa-running-' . Uuid::uuid7()->toString(),
            'correlation_id'  => (string) Uuid::uuid7(),
            'created_at'      => now(),
        ]);

        $response = $this->actingAsAdminWithPermission('qa.execute')
            ->withHeaders(['Idempotency-Key' => 'qa-409-test'])
            ->postJson('/v1/qa/unit-test-authentication');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'QA_CATEGORY_RUNNING');
    }
}
