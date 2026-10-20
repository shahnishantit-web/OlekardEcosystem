<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('Email change atomic rollback (T066)', function (): void {

    it('rolls back profile.email and preserves pending_email_challenge_id when IdentityContactUpdateException is thrown', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'email'                      => 'original@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        // OTP verification succeeds
        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('verify')->with($challengeId, '123456')->once()->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        // Identity update FAILS — simulates an identity service outage
        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->with($userId, 'new@example.com', null)
            ->once()
            ->andThrow(new IdentityContactUpdateException('Identity service unavailable.'));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $response = $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Returns retryable 500 INTERNAL_ERROR
        $response->assertStatus(500);
        expect($response->json('error.code'))->toBe('INTERNAL_ERROR');

        // profile.user_profiles.email MUST be unchanged
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->email)->toBe('original@example.com', 'profile.email must not be updated on rollback');

        // pending_email_challenge_id MUST be preserved (challenge active for retry)
        expect($profile->pending_email_challenge_id)->toBe($challengeId, 'challenge_id must be preserved for retry');
        expect($profile->pending_email)->toBe('new@example.com', 'pending_email must be preserved for retry');
    });

    it('does NOT emit Profile.User.EmailChanged to outbox on rollback', function (): void {
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
        $mockOtp->shouldReceive('verify')->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->andThrow(new IdentityContactUpdateException('Identity service unavailable.'));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $this->postJson(
            '/v1/users/profile/email/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // EmailChanged event MUST NOT be emitted on rollback
        $event = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.EmailChanged')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->toBeNull('Profile.User.EmailChanged must NOT be emitted on rollback');
    });
});
