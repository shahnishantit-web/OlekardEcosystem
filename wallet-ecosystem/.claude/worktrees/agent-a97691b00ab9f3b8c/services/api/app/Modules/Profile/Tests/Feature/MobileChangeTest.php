<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
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

// ---------------------------------------------------------------------------
// POST /v1/users/profile/mobile/change — Initiation
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/mobile/change — Initiation (T076)', function (): void {

    it('returns 200 with challenge_id and emits both events to outbox', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();
        $idempKey    = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempKey],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.challenge_id'))->toBe($challengeId);

        // Profile.User.MobileChangeInitiated in outbox — payload must NOT contain raw mobile
        $initiated = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.MobileChangeInitiated')
            ->where('aggregate_id', $userId)
            ->first();
        expect($initiated)->not->toBeNull('Profile.User.MobileChangeInitiated must be written to outbox');
        $payload = json_decode($initiated->payload, true);
        expect($payload['challenge_id'])->toBe($challengeId);
        expect($payload)->not->toHaveKey('new_mobile');       // raw PII must never appear
        expect($payload)->toHaveKey('new_mobile_masked');

        // Profile.User.ContactSecurityAlertSent in outbox with contact_type=mobile
        $alert = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.ContactSecurityAlertSent')
            ->where('aggregate_id', $userId)
            ->first();
        expect($alert)->not->toBeNull('Profile.User.ContactSecurityAlertSent must be written to outbox');
        $alertPayload = json_decode($alert->payload, true);
        expect($alertPayload['contact_type'])->toBe('mobile');
    });

    it('original mobile unchanged after initiation; pending_mobile stored internally', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->mobile_number)->toBe('+26771000000');   // live mobile unchanged
        expect($profile->pending_mobile)->toBe('+26771999999');  // pending stored
    });

    it('returns 400 SAME_CONTACT_VALUE when new mobile matches current mobile', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        $response = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771000000'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('SAME_CONTACT_VALUE');
    });

    it('returns 400 INVALID_MOBILE_FORMAT for non-E.164 mobile numbers', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);

        $invalidNumbers = ['07712345678', '26771000000', '+0123456789', '+1'];

        foreach ($invalidNumbers as $number) {
            $response = $this->postJson(
                '/v1/users/profile/mobile/change',
                ['new_mobile' => $number],
                ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
            );
            $response->assertStatus(400);
            expect($response->json('error.code'))->toBe('INVALID_MOBILE_FORMAT');
        }
    });

    it('returns 409 CONTACT_CHANGE_IN_PROGRESS when mobile change already pending', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771888888',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $response = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CONTACT_CHANGE_IN_PROGRESS');
    });

    it('returns 200 with same challenge_id on idempotent replay (same key and payload)', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();
        $idempKey    = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $payload = ['new_mobile' => '+26771999999'];
        $headers = ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempKey];

        $first  = $this->postJson('/v1/users/profile/mobile/change', $payload, $headers);
        $second = $this->postJson('/v1/users/profile/mobile/change', $payload, $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        expect($second->json('data.challenge_id'))->toBe($first->json('data.challenge_id'));
    });

    it('returns 409 IDEMPOTENCY_KEY_MISMATCH when same key used with different payload', function (): void {
        $userId         = (string) Uuid::uuid7();
        $challengeId    = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        )->assertStatus(200);

        // Same key, different mobile → 409 IDEMPOTENCY_KEY_MISMATCH
        $response = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771888888'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('IDEMPOTENCY_KEY_MISMATCH');
    });

    it('returns 401 without authentication token', function (): void {
        $response = $this->postJson('/v1/users/profile/mobile/change', ['new_mobile' => '+26771999999']);
        $response->assertStatus(401);
    });

    it('silent mobile uniqueness check — identical response whether mobile is in use or not', function (): void {
        $userId1 = (string) Uuid::uuid7();
        $userId2 = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);

        // User1 owns +26771111111
        $repo->create(['user_id' => $userId1, 'account_status' => 'active']);
        $repo->update($userId1, ['mobile_number' => '+26771111111']);

        // User2 attempts to change to +26771111111 (already in use)
        $repo->create(['user_id' => $userId2, 'account_status' => 'active']);
        $repo->update($userId2, ['mobile_number' => '+26771222222']);

        $challengeId   = (string) Uuid::uuid7();
        $fakeChallenge = new OtpChallenge();
        $fakeChallenge->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        // The initiation request must return 200 regardless — no disclosure of whether the number is in use
        $response = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771111111'],
            ['X-Test-User-Id' => $userId2, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(200);
        expect($response->json('data.challenge_id'))->not->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// POST /v1/users/profile/mobile/verify — Verification
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/mobile/verify — Verification (T076)', function (): void {

    it('verifies OTP, updates profile mobile_number, emits MobileChanged to outbox, writes audit', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771999999',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->with($userId, null, '+26771999999')
            ->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        // Response must contain masked mobile — never raw number
        $maskedMobile = $response->json('data.mobile_number');
        expect($maskedMobile)->not->toBeNull();
        expect($maskedMobile)->not->toBe('+26771999999'); // must be masked

        // profile.user_profiles.mobile_number updated
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->mobile_number)->toBe('+26771999999');
        expect($profile->pending_mobile)->toBeNull();
        expect($profile->pending_mobile_challenge_id)->toBeNull();

        // Profile.User.MobileChanged in outbox — payload must NOT contain raw mobile
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.MobileChanged')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.User.MobileChanged must be written to outbox');
        $eventPayload = json_decode($event->payload, true);
        expect($eventPayload)->not->toHaveKey('new_mobile');        // raw PII must not appear
        expect($eventPayload)->toHaveKey('new_mobile_masked');

        // profile.user.mobile-changed audit record present
        $audit = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.mobile-changed')
            ->where('resource_id', $userId)
            ->first();
        expect($audit)->not->toBeNull('profile.user.mobile-changed audit record must be written');
    });

    it('returns 404 CHALLENGE_NOT_FOUND when challenge_id does not match pending', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        // No pending_mobile_challenge_id set

        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => (string) Uuid::uuid7(), 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('CHALLENGE_NOT_FOUND');
    });

    it('returns 400 OTP_EXPIRED and nullifies pending fields when OTP is expired', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771999999',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')
            ->with($challengeId, '000000')
            ->once()
            ->andThrow(\App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException::expired());
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '000000'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('OTP_EXPIRED');

        // Pending fields must be cleared (challenge consumed, user must re-initiate)
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_mobile)->toBeNull();
        expect($profile->pending_mobile_challenge_id)->toBeNull();
    });

    it('returns 400 OTP_INVALID for wrong OTP code without clearing pending fields', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771999999',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')
            ->with($challengeId, '999999')
            ->once()
            ->andThrow(\App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException::invalidCode());
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('OTP_INVALID');

        // Pending fields preserved — user may retry with same challenge
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_mobile)->toBe('+26771999999');
        expect($profile->pending_mobile_challenge_id)->toBe($challengeId);
    });

    it('returns 409 CHALLENGE_ALREADY_COMPLETED when challenge was already successfully verified', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771999999',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')->with($userId, null, '+26771999999')->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        // First verify succeeds
        $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        )->assertStatus(200);

        // Confirm tracking column was written
        $profile = $repo->findByUserId($userId);
        expect($profile->last_completed_mobile_challenge_id)->toBe($challengeId);

        // Second verify with DIFFERENT idempotency key but same challenge_id → 409
        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CHALLENGE_ALREADY_COMPLETED');
    });

    it('returns 401 without authentication token', function (): void {
        $response = $this->postJson('/v1/users/profile/mobile/verify', [
            'challenge_id' => (string) Uuid::uuid7(),
            'otp'          => '123456',
        ]);
        $response->assertStatus(401);
    });
});
