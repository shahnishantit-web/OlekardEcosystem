<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Jobs\ConfigurationActivationJob;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T064 — FR-012: ConfigurationActivationJob restart idempotency.
 *
 * Running the job twice for the same approved change must produce exactly one
 * `Activated` version record — not two. Prevents duplicate audit records on
 * process restart or queue redelivery.
 */
final class ConfigurationActivationIdempotencyTest extends PlatformTestCase
{
    private string $configKey     = 'financial_policy.idempotency_test_limit';
    private string $changeRequestId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => $this->configKey,
            'domain'      => 'financial_policy',
            'description' => 'Config key used by E28 T064 idempotency test',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        app(ConfigurationService::class)->invalidate($this->configKey);
    }

    public function test_activation_job_is_idempotent_for_approved_change(): void
    {
        $this->changeRequestId = (string) Uuid::uuid7();
        $makerId   = (string) Uuid::uuid7();
        $checkerId = (string) Uuid::uuid7();

        // Seed an approved change request
        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => $this->changeRequestId,
            'config_key'      => $this->configKey,
            'domain'          => 'financial_policy',
            'proposed_value'  => json_encode(7500),
            'previous_value'  => null,
            'maker_id'        => $makerId,
            'status'          => 'approved',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subMinutes(3),
            'resolved_at'     => now()->subMinutes(2),
            'created_at'      => now()->subMinutes(3),
            'updated_at'      => now()->subMinutes(2),
        ]);

        // Seed a draft configuration value tied to the change request
        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id'                => (string) Uuid::uuid7(),
            'config_key'        => $this->configKey,
            'config_value'      => '7500',
            'value_type'        => 'string',
            'status'            => 'draft',
            'version'           => 1,
            'is_tbd'            => false,
            'change_request_id' => $this->changeRequestId,
            'created_at'        => now()->subMinutes(2),
            'updated_at'        => now()->subMinutes(2),
        ]);

        $job = new ConfigurationActivationJob(
            app(ConfigurationService::class),
            app(NotificationModuleContract::class),
        );

        // First run — activates the draft and writes one Activated version record
        $job->handle();

        $activatedCountAfterFirstRun = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('config_key', $this->configKey)
            ->where('event_type', VersionEventType::Activated->value)
            ->where('change_request_id', $this->changeRequestId)
            ->count();

        $this->assertEquals(1, $activatedCountAfterFirstRun, 'First run must write exactly one Activated version record');

        // The draft row is now active — verify it
        $activeCount = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $this->configKey)
            ->where('status', 'active')
            ->count();

        $this->assertEquals(1, $activeCount, 'Exactly one active row after first activation');

        // Second run — no drafts remain; job is a no-op
        $job->handle();

        $activatedCountAfterSecondRun = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('config_key', $this->configKey)
            ->where('event_type', VersionEventType::Activated->value)
            ->where('change_request_id', $this->changeRequestId)
            ->count();

        $this->assertEquals(
            1,
            $activatedCountAfterSecondRun,
            'Second run must NOT produce an additional Activated version record (FR-012 idempotency)'
        );

        // Confirm configuration value still active and unchanged
        $activeRow = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $this->configKey)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($activeRow);
        $this->assertEquals('7500', $activeRow->config_value);
    }

    public function test_second_job_run_does_not_create_duplicate_audit_record(): void
    {
        $changeRequestId = (string) Uuid::uuid7();
        $draftConfigKey  = 'financial_policy.idempotency_audit_test';

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => $draftConfigKey,
            'domain'      => 'financial_policy',
            'description' => 'Config key for T064 audit idempotency test',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => $changeRequestId,
            'config_key'      => $draftConfigKey,
            'domain'          => 'financial_policy',
            'proposed_value'  => json_encode(3000),
            'previous_value'  => null,
            'maker_id'        => (string) Uuid::uuid7(),
            'status'          => 'approved',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subMinutes(2),
            'resolved_at'     => now()->subMinutes(1),
            'created_at'      => now()->subMinutes(2),
            'updated_at'      => now()->subMinutes(1),
        ]);

        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id'                => (string) Uuid::uuid7(),
            'config_key'        => $draftConfigKey,
            'config_value'      => '3000',
            'value_type'        => 'string',
            'status'            => 'draft',
            'version'           => 1,
            'is_tbd'            => false,
            'change_request_id' => $changeRequestId,
            'created_at'        => now()->subMinutes(1),
            'updated_at'        => now()->subMinutes(1),
        ]);

        $job = new ConfigurationActivationJob(
            app(ConfigurationService::class),
            app(NotificationModuleContract::class),
        );

        $auditCountBefore = DB::connection('platform')
            ->table('platform.configuration_audit')
            ->where('action', 'activated')
            ->count();

        $job->handle();

        $auditCountAfterFirst = DB::connection('platform')
            ->table('platform.configuration_audit')
            ->where('action', 'activated')
            ->count();

        $this->assertEquals($auditCountBefore + 1, $auditCountAfterFirst, 'First run writes one audit entry');

        $job->handle();

        $auditCountAfterSecond = DB::connection('platform')
            ->table('platform.configuration_audit')
            ->where('action', 'activated')
            ->count();

        $this->assertEquals($auditCountAfterFirst, $auditCountAfterSecond, 'Second run must not create additional audit entries — idempotent');
    }
}
