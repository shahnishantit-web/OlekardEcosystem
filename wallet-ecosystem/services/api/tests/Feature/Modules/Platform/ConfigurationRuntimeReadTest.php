<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Jobs\ConfigurationActivationJob;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T063 — FR-004: No hard-coded values; runtime configurability end-to-end.
 *
 * Change `financial_policy.max_single_transfer` via the maker-checker workflow,
 * activate it via ConfigurationActivationJob, and assert ConfigurationService::get()
 * returns the new value rather than any stale constant.
 */
final class ConfigurationRuntimeReadTest extends PlatformTestCase
{
    private string $configKey = 'financial_policy.max_single_transfer';
    private string $changeRequestId;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the config key definition
        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => $this->configKey,
            'domain'      => 'financial_policy',
            'description' => 'Maximum transfer amount for a single transaction',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Seed an existing active value (simulating previously configured limit)
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            'id'           => (string) Uuid::uuid7(),
            'config_key'   => $this->configKey,
            'config_value' => '5000',
            'value_type'   => 'string',
            'status'       => 'active',
            'version'      => 1,
            'is_tbd'       => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Flush any Redis cache for this key
        app(ConfigurationService::class)->invalidate($this->configKey);
    }

    public function test_config_service_reads_new_value_after_activation(): void
    {
        $initialValue = app(ConfigurationService::class)->get($this->configKey);
        $this->assertEquals('5000', $initialValue, 'Pre-condition: initial active value is 5000');

        // Simulate the approval step: write a draft configuration_value
        $this->changeRequestId = (string) Uuid::uuid7();
        $makerId   = (string) Uuid::uuid7();
        $checkerId = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => $this->changeRequestId,
            'config_key'      => $this->configKey,
            'domain'          => 'financial_policy',
            'proposed_value'  => json_encode(10000),
            'previous_value'  => json_encode(5000),
            'maker_id'        => $makerId,
            'status'          => 'approved',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subMinutes(2),
            'resolved_at'     => now()->subMinutes(1),
            'created_at'      => now()->subMinutes(2),
            'updated_at'      => now()->subMinutes(1),
        ]);

        // Write the draft config value that activation job will promote
        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id'                => (string) Uuid::uuid7(),
            'config_key'        => $this->configKey,
            'config_value'      => '10000',
            'value_type'        => 'string',
            'status'            => 'draft',
            'version'           => 2,
            'is_tbd'            => false,
            'change_request_id' => $this->changeRequestId,
            'created_at'        => now()->subMinutes(1),
            'updated_at'        => now()->subMinutes(1),
        ]);

        // Run the activation job
        $job = new ConfigurationActivationJob(
            app(ConfigurationService::class),
            app(NotificationModuleContract::class),
        );
        $job->handle();

        // Assert ConfigurationService::get() now returns the new value (FR-004)
        $newValue = app(ConfigurationService::class)->get($this->configKey);
        $this->assertEquals('10000', $newValue, 'ConfigurationService::get() must return the approved value after activation — no hard-coded fallback');

        // Verify the old active row was archived
        $archivedCount = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $this->configKey)
            ->where('status', 'archived')
            ->count();

        $this->assertGreaterThanOrEqual(1, $archivedCount, 'Previous active row must be archived after activation');
    }

    public function test_config_service_does_not_return_draft_value_before_activation(): void
    {
        $this->changeRequestId = (string) Uuid::uuid7();
        $makerId = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.configuration_change_requests')->insert([
            'id'              => $this->changeRequestId,
            'config_key'      => $this->configKey,
            'domain'          => 'financial_policy',
            'proposed_value'  => json_encode(99999),
            'previous_value'  => json_encode(5000),
            'maker_id'        => $makerId,
            'status'          => 'approved',
            'idempotency_key' => (string) Uuid::uuid7(),
            'submitted_at'    => now()->subMinutes(10),
            'resolved_at'     => now()->subMinutes(5),
            'created_at'      => now()->subMinutes(10),
            'updated_at'      => now()->subMinutes(5),
        ]);

        DB::connection('platform')->table('platform.configuration_values')->insert([
            'id'                => (string) Uuid::uuid7(),
            'config_key'        => $this->configKey,
            'config_value'      => '99999',
            'value_type'        => 'string',
            'status'            => 'draft',
            'version'           => 3,
            'is_tbd'            => false,
            'change_request_id' => $this->changeRequestId,
            'effective_from'    => now()->addHour(), // in the future — prevents immediate activation
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Before activation job runs, the service must NOT return the draft value (SC-001)
        $valueBeforeActivation = app(ConfigurationService::class)->get($this->configKey);
        $this->assertEquals('5000', $valueBeforeActivation, 'Draft value must not be visible via ConfigurationService::get() before activation');
    }
}
