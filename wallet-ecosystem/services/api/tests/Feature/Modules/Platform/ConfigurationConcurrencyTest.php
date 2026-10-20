<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T035 — SC-006 Concurrency test: simultaneous approve requests.
 * Asserts exactly one succeeds and exactly one `activated` version record is created.
 *
 * Note: True parallel goroutine-level concurrency is not achievable in a single PHP process.
 * This test simulates contention via sequential DB transactions with explicit lockForUpdate(),
 * verifying that the second attempt throws the expected BusinessRuleViolationException.
 */
final class ConfigurationConcurrencyTest extends PlatformTestCase
{
    private string $makerAdminId;
    private string $checker1AdminId;
    private string $checker2AdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makerAdminId    = (string) Uuid::uuid7();
        $this->checker1AdminId = (string) Uuid::uuid7();
        $this->checker2AdminId = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => 'operational_policy.concurrency_test',
            'domain'      => 'operational_policy',
            'description' => 'Key for concurrency test E28',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function test_concurrent_approvals_only_one_succeeds(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        // Submit change request
        $changeRequest = $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.concurrency_test',
            proposedValue:  ['value' => 42],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $changeRequestId = $changeRequest['id'];
        $successCount    = 0;
        $failureCount    = 0;

        // Simulate two sequential "concurrent" approval attempts
        foreach ([$this->checker1AdminId, $this->checker2AdminId] as $checkerId) {
            try {
                $service->approve(new ApproveChangeRequestDTO(
                    changeRequestId: $changeRequestId,
                    checkerId:       $checkerId,
                    idempotencyKey:  (string) Uuid::uuid7(),
                    correlationId:   (string) Uuid::uuid7(),
                ));
                $successCount++;
            } catch (\App\Exceptions\BusinessRuleViolationException $e) {
                $failureCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Exactly one approval should succeed');
        $this->assertEquals(1, $failureCount, 'Exactly one approval should fail');

        // Only one approved version record should exist
        $approvedVersions = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $changeRequestId)
            ->where('event_type', VersionEventType::Approved->value)
            ->count();

        $this->assertEquals(1, $approvedVersions, 'Only one approved version record should exist');
    }
}
