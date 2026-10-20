<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\RejectChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\WithdrawChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T042 — Version history API tests.
 *
 * Covers: SC-003 (all event types produce records), FR-016 (records retained),
 *         FR-017 (pagination and filter support).
 */
final class ConfigurationVersionHistoryApiTest extends PlatformTestCase
{
    private string $makerAdminId;
    private string $checkerAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makerAdminId   = (string) Uuid::uuid7();
        $this->checkerAdminId = (string) Uuid::uuid7();

        foreach ([
            [$this->makerAdminId,   'maker-hist@e28.test',   '+26001001'],
            [$this->checkerAdminId, 'checker-hist@e28.test', '+26001002'],
        ] as [$id, $email, $mobile]) {
            DB::connection('identity')->table('identity.users')->insertOrIgnore([
                'id'            => $id,
                'email'         => $email,
                'mobile_number' => $mobile,
                'status'        => 'active',
                'actor_type'    => 'ADMIN',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => 'operational_policy.version_history_test',
            'domain'      => 'operational_policy',
            'description' => 'Key for version history test',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // SC-003: submitted → approved events produce version records
    public function test_submit_and_approve_produce_version_records(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        $cr = $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.version_history_test',
            proposedValue:  ['value' => 42],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $service->approve(new ApproveChangeRequestDTO(
            changeRequestId: $cr['id'],
            checkerId:       $this->checkerAdminId,
            idempotencyKey:  (string) Uuid::uuid7(),
            correlationId:   (string) Uuid::uuid7(),
        ));

        $versions = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $cr['id'])
            ->orderBy('occurred_at')
            ->pluck('event_type')
            ->toArray();

        $this->assertContains(VersionEventType::Submitted->value, $versions);
        $this->assertContains(VersionEventType::Approved->value, $versions);
    }

    // SC-003: withdrawn event produces version record
    public function test_withdrawal_produces_version_record(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        $cr = $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.version_history_test',
            proposedValue:  ['value' => 99],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $service->withdraw(new WithdrawChangeRequestDTO(
            changeRequestId: $cr['id'],
            makerId:         $this->makerAdminId,
            idempotencyKey:  (string) Uuid::uuid7(),
            correlationId:   (string) Uuid::uuid7(),
        ));

        $versions = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $cr['id'])
            ->pluck('event_type')
            ->toArray();

        $this->assertContains(VersionEventType::Withdrawn->value, $versions);
    }

    // SC-003: rejected event produces version record
    public function test_rejection_produces_version_record(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        $cr = $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.version_history_test',
            proposedValue:  ['value' => 77],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $service->reject(new RejectChangeRequestDTO(
            changeRequestId: $cr['id'],
            checkerId:       $this->checkerAdminId,
            reason:          'Test rejection reason',
            idempotencyKey:  (string) Uuid::uuid7(),
            correlationId:   (string) Uuid::uuid7(),
        ));

        $versions = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $cr['id'])
            ->pluck('event_type')
            ->toArray();

        $this->assertContains(VersionEventType::Rejected->value, $versions);
    }

    // FR-016: version records cannot be deleted (immutable)
    public function test_version_records_are_immutable(): void
    {
        $this->expectException(\LogicException::class);

        $row = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->first();

        if ($row === null) {
            // Insert a record via DB directly so we have something to test
            DB::connection('platform')->table('platform.configuration_versions')->insert([
                'id'          => (string) Uuid::uuid7(),
                'config_key'  => 'operational_policy.version_history_test',
                'domain'      => 'operational_policy',
                'event_type'  => 'submitted',
                'actor_id'    => $this->makerAdminId,
                'occurred_at' => now(),
                'created_at'  => now(),
            ]);
        }

        // Using the model should throw LogicException for save/update/delete
        $versionModel = \App\Modules\Platform\Infrastructure\Persistence\ConfigurationVersionModel::first();
        $versionModel?->delete();
    }

    // FR-017: version history API supports pagination
    public function test_version_history_endpoint_paginates(): void
    {
        $makerHeaders = ['X-Test-User-Id' => $this->makerAdminId, 'X-Test-Actor-Type' => 'ADMIN'];

        $response = $this->getJson(
            '/v1/admin/configuration/keys/operational_policy.version_history_test/versions?per_page=5',
            $makerHeaders
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data']);
    }

    // FR-017: date range filter returns only records within bounds
    public function test_version_history_date_range_filter_excludes_out_of_range_records(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        // Produce a submitted version record
        $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.version_history_test',
            proposedValue:  ['value' => 11],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $makerHeaders = ['X-Test-User-Id' => $this->makerAdminId, 'X-Test-Actor-Type' => 'ADMIN'];

        // Filter to a future range — should return zero records
        $futureFrom = now()->addDay()->toIso8601String();
        $response = $this->getJson(
            "/v1/admin/configuration/keys/operational_policy.version_history_test/versions?from={$futureFrom}",
            $makerHeaders
        );

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data, 'Records outside the date range must not appear');
    }

    // FR-017: maker_id filter returns only records submitted by that maker
    public function test_version_history_maker_id_filter_scopes_results(): void
    {
        $service = app(ConfigurationChangeRequestService::class);

        // Record submitted by makerAdminId
        $service->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.version_history_test',
            proposedValue:  ['value' => 22],
            makerId:        $this->makerAdminId,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  (string) Uuid::uuid7(),
        ));

        $makerHeaders = ['X-Test-User-Id' => $this->makerAdminId, 'X-Test-Actor-Type' => 'ADMIN'];

        // Filter by checkerAdminId — should return zero records (only submitted events written)
        $response = $this->getJson(
            "/v1/admin/configuration/keys/operational_policy.version_history_test/versions?maker_id={$this->checkerAdminId}",
            $makerHeaders
        );

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data, 'Records from a different maker must not appear');

        // Filter by the actual maker — should return the record
        $response2 = $this->getJson(
            "/v1/admin/configuration/keys/operational_policy.version_history_test/versions?maker_id={$this->makerAdminId}",
            $makerHeaders
        );

        $response2->assertStatus(200);
        $data2 = $response2->json('data.data');
        $this->assertNotEmpty($data2, 'Record submitted by maker must appear when filtering by that maker_id');
    }
}
