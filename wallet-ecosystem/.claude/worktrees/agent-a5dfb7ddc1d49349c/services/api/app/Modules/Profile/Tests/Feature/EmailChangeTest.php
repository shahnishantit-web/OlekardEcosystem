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

describe('POST /v1/users/profile/email/change — Initiation (T064)', function (): void {

    it('returns 200 with challenge_id and emits both events to outbox', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();
        $idempKey    = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        // Mock OtpService so no real OTP/Redis infrastructure is needed.
        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'new@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempKey],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.challenge_id'))->toBe($challengeId);

        // Profile.User.EmailChangeInitiated in outbox
        $initiated = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.EmailChangeInitiated')
            ->where('aggregate_id', $userId)
            ->first();
        expect($initiated)->not->toBeNull('Profile.User.EmailChangeInitiated must be written to outbox');
        $payload = json_decode($initiated->payload, true);
        expect($payload['challenge_id'])->toBe($challengeId);
        expect($payload)->not->toHaveKey('new_email'); // only masked value

        // Profile.User.ContactSecurityAlertSent in outbox
        $alert = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.ContactSecurityAlertSent')
            ->where('aggregate_id', $userId)
            ->first();
        expect($alert)->not->toBeNull('Profile.User.ContactSecurityAlertSent must be written to outbox');
        $alertPayload = json_decode($alert->payload, true);
        expect($alertPayload['contact_type'])->toBe('email');
    });

    it('GET /v1/users/profile still shows original email after initiation', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'pending@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Profile email unchanged — pending_email is internal state only
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->email)->toBe('original@example.com');
        expect($profile->pending_email)->toBe('pending@example.com');
    });

    it('returns 400 SAME_CONTACT_VALUE when new email matches current email', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'current@example.com']);

        $response = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'current@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('SAME_CONTACT_VALUE');
    });

    it('returns 401 without authentication token', function (): void {
        $response = $this->postJson('/v1/users/profile/email/change', ['new_email' => 'x@y.com']);
        $response->assertStatus(401);
    });
});

describe('POST /v1/users/profile/email/verify — Verification (T065)', function (): void {

    it('verifies OTP, updates profile email, emits EmailChanged to outbox, writes audit', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        // OtpService::verify() succeeds
        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        // IdentityModuleContract::updateUserContact() succeeds
        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->with($userId, 'new@example.com', null)
            ->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.email'))->not->toBeNull(); // masked email

        // profile.user_profiles.email updated
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->email)->toBe('new@example.com');
        expect($profile->pending_email)->toBeNull();
        expect($profile->pending_email_challenge_id)->toBeNull();

        // Profile.User.EmailChanged in outbox
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.EmailChanged')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.User.EmailChanged must be written to outbox');

        // profile.user.email-changed audit record present
        $audit = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.email-changed')
            ->where('resource_id', $userId)
            ->first();
        expect($audit)->not->toBeNull('profile.user.email-changed audit record must be written');
    });

    it('returns 404 CHALLENGE_NOT_FOUND when challenge_id does not match pending', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        // No pending_email_challenge_id set

        $response = $this->postJson(
            '/v1/users/profile/email/verify',
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
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')
            ->with($challengeId, '000000')
            ->once()
            ->andThrow(\App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException::expired());
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '000000'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('OTP_EXPIRED');

        // Pending fields must be cleared (challenge consumed, user must re-initiate)
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_email)->toBeNull();
        expect($profile->pending_email_challenge_id)->toBeNull();
    });

    it('returns 400 OTP_INVALID for wrong OTP code without clearing pending fields', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')
            ->with($challengeId, '999999')
            ->once()
            ->andThrow(\App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException::invalidCode());
        app()->instance(OtpServiceContract::class, $mockOtp);

        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '999999'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('OTP_INVALID');

        // Pending fields preserved — user may retry with same challenge
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_email)->toBe('new@example.com');
        expect($profile->pending_email_challenge_id)->toBe($challengeId);
    });

    it('returns 401 without authentication token', function (): void {
        $response = $this->postJson('/v1/users/profile/email/verify', [
            'challenge_id' => (string) Uuid::uuid7(),
            'otp'          => '123456',
        ]);
        $response->assertStatus(401);
    });
});

describe('Idempotency key mismatch — contact change endpoints (T187)', function (): void {

    it('returns 409 IDEMPOTENCY_KEY_MISMATCH on initiation when same key used with different payload', function (): void {
        $userId         = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();
        $challengeId    = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        // First request succeeds
        $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'new1@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        )->assertStatus(200);

        // Same key, different payload → 409
        $response = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'new2@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('IDEMPOTENCY_KEY_MISMATCH');
        // No second OTP challenge created — pending email remains from first request
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_email)->toBe('new1@example.com');
    });

    it('returns 409 IDEMPOTENCY_KEY_MISMATCH on verification when same key used with different OTP payload', function (): void {
        $userId         = (string) Uuid::uuid7();
        $challengeId    = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')->with($userId, 'new@example.com', null)->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        // First verify succeeds
        $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        )->assertStatus(200);

        // Same key, different OTP → 409 (acquireLock detects hash mismatch before service is called)
        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '654321'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempotencyKey],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('IDEMPOTENCY_KEY_MISMATCH');
    });
});

describe('CHALLENGE_ALREADY_COMPLETED detection (T189)', function (): void {

    it('returns 409 CHALLENGE_ALREADY_COMPLETED when challenge was already successfully verified', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')->with($userId, 'new@example.com', null)->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        // First verify succeeds — last_completed_email_challenge_id is stored
        $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        )->assertStatus(200);

        // Confirm tracking column was written
        $profile = $repo->findByUserId($userId);
        expect($profile->last_completed_email_challenge_id)->toBe($challengeId);

        // Second verify with DIFFERENT idempotency key but same challenge_id → 409
        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CHALLENGE_ALREADY_COMPLETED');
    });
});
