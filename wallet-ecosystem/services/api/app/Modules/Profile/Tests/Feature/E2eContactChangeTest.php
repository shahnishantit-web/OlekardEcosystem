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
// T166 — E2E Scenario 3: Mobile Change full lifecycle
// ---------------------------------------------------------------------------

describe('E2E Scenario 3: Mobile Change — initiate → security alert → verify OTP → both records updated → MobileChanged in outbox (T166)', function (): void {

    it('full mobile change lifecycle: initiation emits security alert; verification updates profile and identity; MobileChanged in outbox', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        // ── Step 1: Initiate mobile change ────────────────────────────────────
        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $mockOtpInit = Mockery::mock(OtpServiceContract::class);
        $mockOtpInit->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtpInit);

        $initiateResponse = $this->postJson(
            '/v1/users/profile/mobile/change',
            ['new_mobile' => '+26771999999'],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $initiateResponse->assertStatus(200);
        expect($initiateResponse->json('success'))->toBeTrue();
        expect($initiateResponse->json('data.challenge_id'))->toBe($challengeId);

        // Security alert event emitted at initiation (cross-module: notification boundary)
        $alertEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.ContactSecurityAlertSent')
            ->where('aggregate_id', $userId)
            ->first();
        expect($alertEvent)->not->toBeNull('ContactSecurityAlertSent must be in outbox after initiation');
        $alertPayload = json_decode($alertEvent->payload, true);
        expect($alertPayload['contact_type'])->toBe('mobile');

        // Original mobile unchanged; pending mobile stored internally (not in API response)
        $profileAfterInit = $repo->findByUserId($userId);
        expect($profileAfterInit->mobile_number)->toBe('+26771000000', 'Live mobile must be unchanged after initiation');
        expect($profileAfterInit->pending_mobile)->toBe('+26771999999');
        expect($profileAfterInit->pending_mobile_challenge_id)->toBe($challengeId);

        // ── Step 2: Verify OTP ────────────────────────────────────────────────
        $fakeConsumed     = new OtpChallenge();
        $fakeConsumed->id = $challengeId;

        $mockOtpVerify = Mockery::mock(OtpServiceContract::class);
        $mockOtpVerify->shouldReceive('verify')
            ->with($challengeId, '123456')
            ->once()
            ->andReturn($fakeConsumed);
        app()->instance(OtpServiceContract::class, $mockOtpVerify);

        // E01 identity contract: updateUserContact must be called to sync identity.users.mobile_number
        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('updateUserContact')
            ->with($userId, null, '+26771999999')
            ->once();
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $verifyResponse = $this->postJson(
            '/v1/users/profile/mobile/verify',
            ['challenge_id' => $challengeId, 'otp' => '123456'],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $verifyResponse->assertStatus(200);
        expect($verifyResponse->json('success'))->toBeTrue();
        // Response must contain masked mobile (not raw number)
        $maskedMobile = $verifyResponse->json('data.mobile_number');
        expect($maskedMobile)->not->toBeNull();
        expect($maskedMobile)->not->toBe('+26771999999', 'Raw mobile must not appear in verification response');

        // profile.user_profiles.mobile_number updated (E02 DB side)
        $profileAfterVerify = $repo->findByUserId($userId);
        expect($profileAfterVerify->mobile_number)->toBe('+26771999999', 'profile.user_profiles.mobile_number must be updated after OTP verification');
        expect($profileAfterVerify->pending_mobile)->toBeNull();
        expect($profileAfterVerify->pending_mobile_challenge_id)->toBeNull();

        // identity.users.mobile_number updated via E01 contract (verified by mock expectation above:
        // updateUserContact($userId, null, '+26771999999') was called exactly once)

        // Profile.User.MobileChanged in outbox — raw mobile must not appear in payload
        $mobileChangedEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.MobileChanged')
            ->where('aggregate_id', $userId)
            ->first();
        expect($mobileChangedEvent)->not->toBeNull('Profile.User.MobileChanged must be in outbox after OTP verification');
        $changedPayload = json_decode($mobileChangedEvent->payload, true);
        expect($changedPayload)->not->toHaveKey('new_mobile', 'Raw mobile must not appear in MobileChanged event payload');
        expect($changedPayload)->toHaveKey('new_mobile_masked');

        // profile.user.mobile-changed audit record
        $audit = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.mobile-changed')
            ->where('resource_id', $userId)
            ->first();
        expect($audit)->not->toBeNull('profile.user.mobile-changed audit must be recorded after OTP verification');
        expect($audit->outcome)->toBe('success');
    });
});
