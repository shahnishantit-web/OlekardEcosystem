<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('PUT /v1/users/profile/personal', function (): void {

    it('persists updated personal info and returns updated profile', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->putJson('/v1/users/profile/personal', [
            'first_name'        => 'Nishant',
            'last_name'         => 'Shah',
            'preferred_language' => 'en',
        ], [
            'X-Test-User-Id'   => $userId,
            'Idempotency-Key'  => (string) Uuid::uuid7(),
            'X-Correlation-ID' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.first_name'))->toBe('Nishant');
        expect($response->json('data.last_name'))->toBe('Shah');
        expect($response->json('data.preferred_language'))->toBe('en');
        expect($response->json('data.user_id'))->toBe($userId);
        expect($response->json('data'))->not->toHaveKey('profile_photo_key');

        // Verify DB persisted
        $row = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', $userId)->first();
        expect($row->first_name)->toBe('Nishant');
        expect($row->last_name)->toBe('Shah');
    });

    it('updates nested address fields correctly', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->putJson('/v1/users/profile/personal', [
            'address' => [
                'street'      => '123 Main St',
                'city'        => 'Gaborone',
                'country'     => 'BW',
                'postal_code' => 'P100',
            ],
        ], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->json('data.address.street'))->toBe('123 Main St');
        expect($response->json('data.address.city'))->toBe('Gaborone');
        expect($response->json('data.address.country'))->toBe('BW');

        $row = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', $userId)->first();
        expect($row->address_street)->toBe('123 Main St');
        expect($row->address_country)->toBe('BW');
    });

    it('returns same 200 response on duplicate Idempotency-Key (single DB write)', function (): void {
        $userId          = (string) Uuid::uuid7();
        $idempotencyKey  = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $payload = ['first_name' => 'Idempotent'];
        $headers = [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => $idempotencyKey,
        ];

        $response1 = $this->putJson('/v1/users/profile/personal', $payload, $headers);
        $response1->assertStatus(200);

        // Second request with SAME key — must NOT produce a second DB write
        $countBefore = DB::connection('platform')->table('platform.idempotency_records')
            ->where('idempotency_key', $idempotencyKey)->count();

        $response2 = $this->putJson('/v1/users/profile/personal', $payload, $headers);
        $response2->assertStatus(200);

        // Same response body as first
        expect($response2->json('data.first_name'))->toBe($response1->json('data.first_name'));

        // idempotency_records count unchanged (no new record created)
        $countAfter = DB::connection('platform')->table('platform.idempotency_records')
            ->where('idempotency_key', $idempotencyKey)->count();
        expect($countAfter)->toBe($countBefore);
    });

    // T150 — Idempotency concurrency: 10 sequential requests with the same key produce exactly one DB write
    it('10 sequential requests with the same Idempotency-Key produce exactly one DB write', function (): void {
        $userId        = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $headers = [
            'X-Test-User-Id'   => $userId,
            'Idempotency-Key'  => $idempotencyKey,
            'X-Correlation-ID' => (string) Uuid::uuid7(),
        ];

        $payload = ['first_name' => 'Concurrent'];

        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->putJson('/v1/users/profile/personal', $payload, $headers);
        }

        // All 10 requests return 200
        foreach ($responses as $response) {
            $response->assertStatus(200);
            expect($response->json('success'))->toBeTrue();
        }

        // All 10 return identical first_name
        $firstBody = $responses[0]->json('data.first_name');
        foreach ($responses as $response) {
            expect($response->json('data.first_name'))->toBe($firstBody);
        }

        // Exactly one idempotency record for this key — no duplicate writes
        $recordCount = DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        expect($recordCount)->toBe(1, 'Exactly one idempotency record must exist for the key; duplicate writes detected');

        // Personal info DB row updated exactly once (one version, consistent value)
        $profile = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->first();
        expect($profile->first_name)->toBe('Concurrent');
    });

    it('emits Profile.User.PersonalInfoUpdated to outbox', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $correlationId = (string) Uuid::uuid7();

        $this->putJson('/v1/users/profile/personal', [
            'first_name' => 'EventTest',
        ], [
            'X-Test-User-Id'   => $userId,
            'Idempotency-Key'  => (string) Uuid::uuid7(),
            'X-Correlation-ID' => $correlationId,
        ]);

        $event = DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.PersonalInfoUpdated')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.PersonalInfoUpdated must be in outbox');
        expect($event->correlation_id)->toBe($correlationId);
    });

    it('writes profile.user.personal-info-updated audit record', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($userId): bool {
                return $data->action === 'profile.user.personal-info-updated'
                    && $data->actorId === $userId
                    && $data->resourceId === $userId
                    && $data->outcome === 'success';
            });

        app()->instance(AuditServiceInterface::class, $auditService);

        $this->putJson('/v1/users/profile/personal', [
            'last_name' => 'AuditTest',
        ], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);
    });

    it('returns 409 IDEMPOTENCY_KEY_MISMATCH for same key with different payload', function (): void {
        $userId         = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $this->putJson('/v1/users/profile/personal', ['first_name' => 'First'], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => $idempotencyKey,
        ])->assertStatus(200);

        // Same key, different body → mismatch
        $response = $this->putJson('/v1/users/profile/personal', ['first_name' => 'Different'], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('IDEMPOTENCY_KEY_MISMATCH');
    });

    it('returns 400 when Idempotency-Key header is missing', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->putJson('/v1/users/profile/personal', ['first_name' => 'Test'], [
            'X-Test-User-Id' => $userId,
            // No Idempotency-Key header
        ]);

        $response->assertStatus(400);
    });

    it('returns 401 when no token is provided', function (): void {
        $response = $this->putJson('/v1/users/profile/personal', ['first_name' => 'Test']);
        $response->assertStatus(401);
    });
});

describe('PUT /v1/admin/users/{user_id}/profile/personal', function (): void {

    it('admin with profile.update can update any user profile', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->putJson("/v1/admin/users/{$targetId}/profile/personal", [
            'first_name' => 'AdminUpdated',
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'ADMIN',
            'X-Test-Permissions' => 'profile.update',
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->json('data.first_name'))->toBe('AdminUpdated');
        expect($response->json('data.user_id'))->toBe($targetId);
    });

    it('admin update audit records both admin actor and subject user', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($adminId, $targetId): bool {
                return $data->action === 'profile.user.personal-info-updated'
                    && $data->actorId === $adminId
                    && $data->actorType === 'ADMIN'
                    && $data->resourceId === $targetId
                    && ($data->metadata['subject_user_id'] ?? null) === $targetId
                    && $data->outcome === 'success';
            });

        app()->instance(AuditServiceInterface::class, $auditService);

        $this->putJson("/v1/admin/users/{$targetId}/profile/personal", [
            'first_name' => 'AdminAudit',
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'ADMIN',
            'X-Test-Permissions' => 'profile.update',
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);
    });

    it('admin without profile.update permission is denied', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        $response = $this->putJson("/v1/admin/users/{$targetId}/profile/personal", [
            'first_name' => 'Denied',
        ], [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'ADMIN',
            // No X-Test-Permissions
            'Idempotency-Key'   => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });
});
