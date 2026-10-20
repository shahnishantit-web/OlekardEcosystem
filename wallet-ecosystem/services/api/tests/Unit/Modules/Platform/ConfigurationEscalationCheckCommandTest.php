<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\Support\UnitTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T058 — ConfigurationEscalationCheckCommand unit tests.
 */
final class ConfigurationEscalationCheckCommandTest extends UnitTestCase
{
    public function test_stale_request_emits_escalation_audit_event(): void
    {
        config(['configuration.escalation_threshold_hours' => 24]);

        // Seed a stale pending request (older than threshold)
        $staleId = (string) Uuid::uuid7();
        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => $staleId,
            'config_key'      => 'operational_policy.escalation_test',
            'domain'          => 'operational_policy',
            'proposed_value'  => json_encode(['value' => 1]),
            'maker_id'        => (string) Uuid::uuid7(),
            'status'          => 'pending_approval',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subHours(30), // older than 24h threshold
            'created_at'      => now()->subHours(30),
            'updated_at'      => now()->subHours(30),
        ]);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $event) use ($staleId) {
                return $event->action === 'configuration.change_request.escalated'
                    && $event->resourceId === $staleId;
            });

        $this->app->instance(AuditServiceInterface::class, $auditService);

        $this->artisan('configuration:escalation-check')->assertExitCode(0);
    }

    public function test_fresh_request_does_not_emit_escalation_event(): void
    {
        config(['configuration.escalation_threshold_hours' => 24]);

        // Seed a fresh pending request (within threshold)
        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => (string) Uuid::uuid7(),
            'config_key'      => 'operational_policy.fresh_test',
            'domain'          => 'operational_policy',
            'proposed_value'  => json_encode(['value' => 2]),
            'maker_id'        => (string) Uuid::uuid7(),
            'status'          => 'pending_approval',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subHours(1), // within 24h threshold
            'created_at'      => now()->subHours(1),
            'updated_at'      => now()->subHours(1),
        ]);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldNotReceive('record');

        $this->app->instance(AuditServiceInterface::class, $auditService);

        $this->artisan('configuration:escalation-check')->assertExitCode(0);
    }
}
