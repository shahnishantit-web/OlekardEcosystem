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

describe('Mobile change atomic rollback (T077)', function (): void {

    it('rolls back profile.mobile_number and preserves pending_mobile_challenge_id when IdentityContactUpdateException is thrown', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, [
            'mobile_number'               => '+26771000000',
            'pending_mobile'              => '+26771999999',
            'pending_mobile_challenge_id' => $challengeId,
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
            ->with($userId, null, '+26771999999')
            ->once()
            ->andThrow(new IdentityContactUpdateException('Identity service unavailable.'));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $response = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Returns retryable 500 INTERNAL_ERROR
        $response->assertStatus(500);
        expect($response->json('error.code'))->toBe('INTERNAL_ERROR');

        // profile.user_profiles.mobile_number MUST be unchanged
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->mobile_number)->toBe('+26771000000', 'profile.mobile_number must not be updated on rollback');

        // pending_mobile_challenge_id MUST be preserved (challenge active for retry)
        expect($profile->pending_mobile_challenge_id)->toBe($challengeId, 'challenge_id must be preserved for retry');
        expect($profile->pending_mobile)->toBe('+26771999999', 'pending_mobile must be preserved for retry');
    });

    it('does NOT emit Profile.User.MobileChanged to outbox on rollback', function (): void {
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
        $mockOtp->shouldReceive('verify')->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->andThrow(new IdentityContactUpdateException('Identity service unavailable.'));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // MobileChanged event MUST NOT be emitted on rollback
        $event = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.MobileChanged')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->toBeNull('Profile.User.MobileChanged must NOT be emitted on rollback');
    });

    it('last_completed_mobile_challenge_id is NOT written when transaction rolls back', function (): void {
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
        $mockOtp->shouldReceive('verify')->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->andThrow(new IdentityContactUpdateException('Identity service unavailable.'));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // last_completed_mobile_challenge_id must remain null — transaction rolled back
        $profile = $repo->findByUserId($userId);
        expect($profile->last_completed_mobile_challenge_id)->toBeNull(
            'last_completed_mobile_challenge_id must not be set when transaction rolls back'
        );
    });
});
