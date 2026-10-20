<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Audit;

use App\Console\Commands\AuditEnforceRetentionCommand;
use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\RetentionPolicyModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class RetentionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-retention']);
    }

    public function test_command_does_not_purge_records_within_retention_window(): void
    {
        $auditMock = $this->mock(AuditModuleContract::class);
        $auditMock->allows('record')->andReturn((string) Uuid::uuid7());

        // Create a policy: purge records older than 30 days
        RetentionPolicyModel::create([
            'data_category'          => 'audit.authentication',
            'minimum_retention_days' => 30,
            'post_retention_action'  => 'purge',
            'effective_from'         => CarbonImmutable::today()->toDateString(),
            'configured_by_actor_id' => (string) Uuid::uuid7(),
        ]);

        // Insert a recent audit event (within window — 1 day old)
        $recentId = (string) Uuid::uuid7();
        DB::connection('platform')->table('audit.audit_events')->insert([
            'id'                => $recentId,
            'event_category'    => 'authentication',
            'event_type'        => 'auth.login.success',
            'actor_type'        => 'user',
            'actor_id'          => (string) Uuid::uuid7(),
            'entity_type'       => 'user',
            'entity_id'         => (string) Uuid::uuid7(),
            'action'            => 'login',
            'outcome'           => 'success',
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'test',
            'correlation_id'    => (string) Uuid::uuid7(),
            'integrity_hash'    => str_repeat('a', 64),
            'occurred_at'       => now()->toDateTimeString(),
            'created_at'        => now()->toDateTimeString(), // within window
        ]);

        $this->artisan('audit:enforce-retention')->assertExitCode(0);

        $this->assertDatabaseHas('audit.audit_events', ['id' => $recentId], 'platform');
    }

    public function test_command_archives_records_beyond_retention_window(): void
    {
        $auditMock = $this->mock(AuditModuleContract::class);
        $auditMock->allows('record')->andReturn((string) Uuid::uuid7());

        // Policy: archive records older than 1 day
        RetentionPolicyModel::create([
            'data_category'          => 'audit.authentication',
            'minimum_retention_days' => 1,
            'post_retention_action'  => 'archive',
            'effective_from'         => CarbonImmutable::today()->toDateString(),
            'configured_by_actor_id' => (string) Uuid::uuid7(),
        ]);

        $oldId = (string) Uuid::uuid7();
        DB::connection('platform')->table('audit.audit_events')->insert([
            'id'                => $oldId,
            'event_category'    => 'authentication',
            'event_type'        => 'auth.login.success',
            'actor_type'        => 'user',
            'actor_id'          => (string) Uuid::uuid7(),
            'entity_type'       => 'user',
            'entity_id'         => (string) Uuid::uuid7(),
            'action'            => 'login',
            'outcome'           => 'success',
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'test',
            'correlation_id'    => (string) Uuid::uuid7(),
            'integrity_hash'    => str_repeat('b', 64),
            'occurred_at'       => now()->subDays(5)->toDateTimeString(),
            'created_at'        => now()->subDays(5)->toDateTimeString(), // beyond window
        ]);

        $this->artisan('audit:enforce-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('audit.audit_events', ['id' => $oldId], 'platform');
        $this->assertDatabaseHas('audit.audit_events_archive', ['id' => $oldId], 'platform');
    }

    public function test_enforcement_hold_until_grace_period_prevents_processing(): void
    {
        $auditMock = $this->mock(AuditModuleContract::class);
        $auditMock->allows('record')->andReturn((string) Uuid::uuid7());

        // Policy with a future enforcement_hold_until
        RetentionPolicyModel::create([
            'data_category'          => 'audit.financial',
            'minimum_retention_days' => 1,
            'post_retention_action'  => 'purge',
            'effective_from'         => CarbonImmutable::today()->toDateString(),
            'enforcement_hold_until' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'configured_by_actor_id' => (string) Uuid::uuid7(),
        ]);

        $oldId = (string) Uuid::uuid7();
        DB::connection('platform')->table('audit.audit_events')->insert([
            'id'                => $oldId,
            'event_category'    => 'financial',
            'event_type'        => 'financial.transfer',
            'actor_type'        => 'user',
            'actor_id'          => (string) Uuid::uuid7(),
            'entity_type'       => 'wallet',
            'entity_id'         => (string) Uuid::uuid7(),
            'action'            => 'transfer',
            'outcome'           => 'success',
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'test',
            'correlation_id'    => (string) Uuid::uuid7(),
            'integrity_hash'    => str_repeat('c', 64),
            'occurred_at'       => now()->subDays(5)->toDateTimeString(),
            'created_at'        => now()->subDays(5)->toDateTimeString(),
        ]);

        $this->artisan('audit:enforce-retention')->assertExitCode(0);

        // Record should NOT be deleted/archived because of grace period
        $this->assertDatabaseHas('audit.audit_events', ['id' => $oldId], 'platform');
    }
}
