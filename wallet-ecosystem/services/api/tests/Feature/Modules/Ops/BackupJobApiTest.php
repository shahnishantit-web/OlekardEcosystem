<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Application\Services\BackupService;
use App\Modules\Ops\Domain\Enums\BackupJobStatus;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Infrastructure\Models\BackupJobModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupJobApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_backup_jobs(): void
    {
        $this->actingAsAdmin();

        BackupJobModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => 'test_backup_' . Str::random(8),
            'type'            => BackupType::Full->value,
            'status'          => BackupJobStatus::Completed->value,
            'storage_path'    => 'backups/2026/10/01/backup_test.json',
            'size_bytes'      => 1024,
        ]);

        $this->getJson('/v1/admin/ops/backup-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'full')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_trigger_backup_returns_accepted(): void
    {
        $this->actingAsAdmin();

        $this->mock(BackupService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(new BackupJobModel([
                'id'              => Str::orderedUuid()->toString(),
                'idempotency_key' => 'test_key',
                'type'            => BackupType::Full->value,
                'status'          => BackupJobStatus::Pending->value,
            ]));
        });

        $this->postJson('/v1/admin/ops/backup-jobs', ['type' => 'full'], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ])->assertStatus(202);
    }

    public function test_idempotent_backup_trigger(): void
    {
        $this->actingAsAdmin();

        $idempotencyKey = Str::uuid()->toString();

        BackupJobModel::create([
            'id'              => Str::orderedUuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'type'            => BackupType::Full->value,
            'status'          => BackupJobStatus::Running->value,
        ]);

        $this->mock(BackupService::class, function ($mock) use ($idempotencyKey) {
            $existing = BackupJobModel::where('idempotency_key', $idempotencyKey)->first();
            $mock->shouldReceive('run')->once()->andReturn($existing);
        });

        $this->postJson('/v1/admin/ops/backup-jobs', [], [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertStatus(202);
    }
}
