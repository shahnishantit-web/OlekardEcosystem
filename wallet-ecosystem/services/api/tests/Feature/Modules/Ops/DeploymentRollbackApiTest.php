<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Application\Services\DeploymentService;
use App\Modules\Ops\Domain\Enums\RollbackStatus;
use App\Modules\Ops\Infrastructure\Models\DeploymentRollbackModel;
use App\Modules\Ops\Infrastructure\Models\DeploymentVersionModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentRollbackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_versions_returns_ok(): void
    {
        $this->actingAsAdmin();

        DeploymentVersionModel::create([
            'id'          => Str::orderedUuid()->toString(),
            'version'     => 'v1.2.0',
            'deployed_at' => Carbon::now(),
            'is_current'  => true,
        ]);

        $this->getJson('/v1/admin/ops/deployments/versions')
            ->assertOk()
            ->assertJsonPath('data.0.version', 'v1.2.0');
    }

    public function test_rollback_requires_to_version(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/v1/admin/ops/deployments/rollback', [], ['Idempotency-Key' => Str::uuid()->toString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_version']);
    }

    public function test_rollback_returns_409_if_in_progress(): void
    {
        $this->actingAsAdmin();

        DeploymentRollbackModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => 'existing_' . Str::random(8),
            'from_version'    => 'v1.2.0',
            'to_version'      => 'v1.1.0',
            'status'          => RollbackStatus::InProgress->value,
        ]);

        $this->postJson('/v1/admin/ops/deployments/rollback', [
            'to_version' => 'v1.0.0',
        ], ['Idempotency-Key' => Str::uuid()->toString()])
            ->assertStatus(409);
    }

    public function test_idempotent_rollback_returns_existing(): void
    {
        $this->actingAsAdmin();

        $idempotencyKey = Str::uuid()->toString();

        $existing = DeploymentRollbackModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'from_version'    => 'v1.2.0',
            'to_version'      => 'v1.1.0',
            'status'          => RollbackStatus::Completed->value,
        ]);

        $this->mock(DeploymentService::class, function ($mock) use ($existing) {
            $mock->shouldReceive('rollback')->once()->andReturn($existing);
        });

        $this->postJson('/v1/admin/ops/deployments/rollback', [
            'to_version' => 'v1.1.0',
        ], ['Idempotency-Key' => $idempotencyKey])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');
    }
}
