<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Infrastructure\Jobs\ProcessDataExportJob;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

// ---------------------------------------------------------------------------
// T117 — Feature tests for POST /v1/users/profile/data-export
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/data-export (T117)', function (): void {

    it('returns 200 with export_id and status=processing and dispatches ProcessDataExportJob', function (): void {
        Queue::fake();

        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            '/v1/users/profile/data-export',
            [],
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.export_id'))->not->toBeNull();
        expect($response->json('data.status'))->toBe('processing');

        // DB: row created with status=processing
        $exportId = $response->json('data.export_id');
        $export   = app(EloquentDataExportRepository::class)->findById($exportId);
        expect($export)->not->toBeNull();
        expect($export->user_id)->toBe($userId);
        expect($export->status)->toBe('processing');

        // Job was dispatched
        Queue::assertPushed(ProcessDataExportJob::class, fn ($job) => $job->userId === $userId && $job->exportId === $exportId);
    });

    it('emits Profile.User.DataExportRequested to outbox', function (): void {
        Queue::fake();

        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            '/v1/users/profile/data-export',
            [],
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(200);
        $exportId = $response->json('data.export_id');

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.DataExportRequested')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.DataExportRequested must be written to outbox');

        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
        expect($payload['export_id'])->toBe($exportId);
    });

    it('writes profile.user.data-export-requested audit record', function (): void {
        Queue::fake();

        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($userId): bool {
                return $data->action === 'profile.user.data-export-requested'
                    && $data->actorId === $userId
                    && $data->actorType === 'USER'
                    && $data->outcome === 'success';
            });
        app()->instance(AuditServiceInterface::class, $auditService);

        $this->postJson('/v1/users/profile/data-export', [], ['X-Test-User-Id' => $userId]);
    });

    it('returns 409 EXPORT_IN_PROGRESS when a processing export already exists', function (): void {
        Queue::fake();

        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // First request → creates processing export
        $this->postJson('/v1/users/profile/data-export', [], ['X-Test-User-Id' => $userId])
            ->assertStatus(200);

        // Second request before job completes → 409
        $response = $this->postJson('/v1/users/profile/data-export', [], ['X-Test-User-Id' => $userId]);

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('EXPORT_IN_PROGRESS');
    });

    it('returns 401 UNAUTHENTICATED when no auth token is provided', function (): void {
        $response = $this->postJson('/v1/users/profile/data-export');
        $response->assertStatus(401);
    });

});

// ---------------------------------------------------------------------------
// T117 — Feature tests for GET /v1/users/profile/data-export/{export_id}
// ---------------------------------------------------------------------------

describe('GET /v1/users/profile/data-export/{export_id} (T117)', function (): void {

    it('returns 200 with status=processing for the owning user', function (): void {
        Queue::fake();

        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $postResponse = $this->postJson('/v1/users/profile/data-export', [], ['X-Test-User-Id' => $userId]);
        $exportId     = $postResponse->json('data.export_id');

        $response = $this->getJson(
            "/v1/users/profile/data-export/{$exportId}",
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.export_id'))->toBe($exportId);
        expect($response->json('data.status'))->toBe('processing');
    });

    it('returns 403 FORBIDDEN when export_id belongs to a different user (IDOR)', function (): void {
        Queue::fake();

        $ownerUserId   = (string) Uuid::uuid7();
        $attackerUserId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $ownerUserId, 'account_status' => 'active']);

        $postResponse = $this->postJson('/v1/users/profile/data-export', [], ['X-Test-User-Id' => $ownerUserId]);
        $exportId     = $postResponse->json('data.export_id');

        $response = $this->getJson(
            "/v1/users/profile/data-export/{$exportId}",
            ['X-Test-User-Id' => $attackerUserId],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('returns 403 FORBIDDEN (not 404) for a non-existent export_id (IDOR silent)', function (): void {
        $userId = (string) Uuid::uuid7();
        $fakeId = (string) Uuid::uuid7();

        $response = $this->getJson(
            "/v1/users/profile/data-export/{$fakeId}",
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('returns 401 UNAUTHENTICATED when no auth token is provided', function (): void {
        $fakeId   = (string) Uuid::uuid7();
        $response = $this->getJson("/v1/users/profile/data-export/{$fakeId}");
        $response->assertStatus(401);
    });

    // T172 — "ready" state: controller must generate signed URL and return it
    it('returns 200 with status=ready and a signed download_url when export is complete (T172)', function (): void {
        $userId   = (string) Uuid::uuid7();
        $exportId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Seed a completed export directly — simulates the job having already run
        DB::connection('profile')->table('profile.data_exports')->insert([
            'id'             => $exportId,
            'user_id'        => $userId,
            'status'         => 'ready',
            'download_key'   => 'exports/' . $userId . '/export.json',
            'expires_at'     => now()->addHour()->toIso8601String(),
            'requested_at'   => now()->toIso8601String(),
            'completed_at'   => now()->toIso8601String(),
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at'     => now()->toIso8601String(),
            'updated_at'     => now()->toIso8601String(),
        ]);

        $mockStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $mockStorage->shouldReceive('generateSignedUrl')
            ->once()
            ->andReturn('https://signed.example.com/export.json?expires=9999999999');
        app()->instance(ProfilePhotoStorageContract::class, $mockStorage);

        $response = $this->getJson(
            "/v1/users/profile/data-export/{$exportId}",
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.export_id'))->toBe($exportId);
        expect($response->json('data.status'))->toBe('ready');
        expect($response->json('data.download_url'))->not->toBeNull(
            'download_url must be present and non-null when export status is ready'
        );
        expect($response->json('data.expires_at'))->not->toBeNull(
            'expires_at must be present when export is in ready state'
        );
    });

});
