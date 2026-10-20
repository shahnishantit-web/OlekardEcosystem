<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/** Create a pre-trusted device for a user so login proceeds without device verification. */
function makeAuthTrustedDevice(string $userId, string $rawFingerprint): void
{
    app(DeviceRepositoryInterface::class)->create([
        'user_id'            => $userId,
        'device_fingerprint' => hash('sha256', $rawFingerprint),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);
}


beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $ch, string $c): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });

    Redis::connection('identity')->flushdb();
});

describe('POST /v1/auth/login â€” PIN', function (): void {

    it('returns 200 with tokens on correct PIN login (trusted device)', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000010',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        makeAuthTrustedDevice($user->id, 'test-fingerprint');

        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771000010',
            'credential'         => '123456',
            'device_fingerprint' => 'test-fingerprint',
            'device_name'        => 'Test Phone',
            'platform'           => 'android',
        ], ['X-Correlation-ID' => 'auth1', 'X-Idempotency-Key' => 'auth-idem1']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data' => ['access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id', 'mfa_required', 'new_device_detected']]);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.mfa_required'))->toBeFalse();
        expect($response->json('data.new_device_detected'))->toBeFalse();
    });

    it('new device login returns 403 IDENTITY_023; trusted device login returns 200 (AC-009)', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);
        $devices  = app(DeviceRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000015',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        // First login â€” untrusted device â†’ device verification required (AC-009)
        $r1 = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771000015',
            'credential'         => '123456',
            'device_fingerprint' => 'brand-new-device',
        ], ['X-Correlation-ID' => 'nd1', 'X-Idempotency-Key' => 'nd-idem1']);

        $r1->assertStatus(403);
        expect($r1->json('error.code'))->toBe('IDENTITY_023');
        expect($r1->json('error.challenge_id'))->not->toBeNull();

        // Manually promote device to trusted (simulates completing device verification)
        $devices->updateStatus(
            $devices->findByUserAndFingerprint($user->id, hash('sha256', 'brand-new-device'))->id,
            'trusted',
        );

        // Second login â€” same trusted device â†’ 200 with tokens
        $r2 = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771000015',
            'credential'         => '123456',
            'device_fingerprint' => 'brand-new-device',
        ], ['X-Correlation-ID' => 'nd2', 'X-Idempotency-Key' => 'nd-idem2']);

        $r2->assertStatus(200);
        $r2->assertJsonStructure(['success', 'data' => ['access_token', 'session_id']]);
    });

    it('returns 401 IDENTITY_002 for wrong PIN without identifier disclosure', function (): void {
        $users   = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000011',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26771000011',
            'credential'         => '999999',
        ], ['X-Correlation-ID' => 'auth2', 'X-Idempotency-Key' => 'auth-idem2']);

        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_002');
        // Error must not disclose identifier (BR-014)
        expect($response->json('error.message'))->not->toContain('+26771000011');
    });

    it('returns 401 for non-existent identifier without disclosing it', function (): void {
        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26770000000',
            'credential'         => '123456',
        ], ['X-Correlation-ID' => 'auth3', 'X-Idempotency-Key' => 'auth-idem3']);

        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_002');
        expect($response->getContent())->not->toContain('+26770000000');
    });

    it('locks account after max failed attempts and returns 423 IDENTITY_004', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);
        $maxAttempts = app(ConfigurationSecurityService::class)->getLockoutMaxFailedAttempts();

        $user = $users->create([
            'mobile_number' => '+26771000012',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('111111'),
            'status'          => 'active',
        ]);

        // Exhaust attempts (need maxAttempts - 1 failures before lockout triggers on the next)
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/login', [
                'credential_type' => 'pin',
                'identifier'      => '+26771000012',
                'credential'         => '999999',
            ], ['X-Correlation-ID' => "lock-{$i}", 'X-Idempotency-Key' => "lock-idem-{$i}"]);
        }

        // Subsequent attempt on locked account
        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26771000012',
            'credential'         => '111111',
        ], ['X-Correlation-ID' => 'lock-final', 'X-Idempotency-Key' => 'lock-idem-final']);

        $response->assertStatus(423);
        expect($response->json('error.code'))->toBe('IDENTITY_004');
    });

    it('progressive lockout extends duration on second episode', function (): void {
        $users       = app(UserRepositoryInterface::class);
        $credRepo    = app(CredentialRepositoryInterface::class);
        $config      = app(ConfigurationSecurityService::class);
        $maxAttempts = $config->getLockoutMaxFailedAttempts();
        $initial     = $config->getLockoutInitialDuration();

        $user = $users->create([
            'mobile_number' => '+26771000040',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('111111'),
            'status'          => 'active',
        ]);

        // First lockout episode
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/login', [
                'credential_type' => 'pin',
                'identifier'      => '+26771000040',
                'credential'         => '999999',
            ], ['X-Correlation-ID' => "ep1-{$i}", 'X-Idempotency-Key' => "ep1-idem-{$i}"]);
        }

        $lockedUser = $users->findByMobile('+26771000040');
        expect($lockedUser->lockout_episode_count)->toBe(1);

        // Simulate lock expiry by back-dating locked_at past the initial duration
        // assertNotLocked will auto-clear the lock on the next attempt
        $users->updateLockedAt($lockedUser->id, now()->subSeconds($initial + 60));

        // Second lockout episode
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/login', [
                'credential_type' => 'pin',
                'identifier'      => '+26771000040',
                'credential'         => '999999',
            ], ['X-Correlation-ID' => "ep2-{$i}", 'X-Idempotency-Key' => "ep2-idem-{$i}"]);
        }

        $lockedUser2 = $users->findByMobile('+26771000040');
        expect($lockedUser2->lockout_episode_count)->toBe(2);
    });

    it('locked account rejects valid credentials with 423 IDENTITY_004', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000050',
            'status'        => 'locked',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);
        $users->updateLockedAt($user->id, now()->subSeconds(30));

        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26771000050',
            'credential'         => '123456',
        ], ['X-Correlation-ID' => 'locked1', 'X-Idempotency-Key' => 'locked-idem1']);

        $response->assertStatus(423);
        expect($response->json('error.code'))->toBe('IDENTITY_004');
    });

})->group('identity', 'authentication', 'feature');

describe('POST /v1/auth/login â€” OTP', function (): void {

    it('returns 200 with tokens on correct OTP login (trusted device)', function (): void {
        $users      = app(UserRepositoryInterface::class);
        $challenges = app(OtpChallengeRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000060',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        makeAuthTrustedDevice($user->id, 'otp-device');

        $knownOtp  = '654321';
        $challenge = $challenges->create([
            'user_id'        => $user->id,
            'challenge_type' => 'login',
            'identifier'     => '+26771000060',
            'otp_hash'       => hash('sha256', $knownOtp),
            'status'         => 'pending',
            'attempt_count'  => 0,
            'resend_count'   => 0,
            'expires_at'     => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'otp',
            'challenge_id'       => $challenge->id,
            'otp'                => $knownOtp,
            'device_fingerprint' => 'otp-device',
            'device_name'        => 'OTP Phone',
            'platform'           => 'android',
        ], ['X-Correlation-ID' => 'otp-login1', 'X-Idempotency-Key' => 'otp-login-idem1']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data' => ['access_token', 'refresh_token', 'session_id']]);
    });

    it('returns 401 IDENTITY_003 for expired OTP challenge', function (): void {
        $users      = app(UserRepositoryInterface::class);
        $challenges = app(OtpChallengeRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000061',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $challenge = $challenges->create([
            'user_id'        => $user->id,
            'challenge_type' => 'login',
            'identifier'     => '+26771000061',
            'otp_hash'       => hash('sha256', '111111'),
            'status'         => 'pending',
            'attempt_count'  => 0,
            'resend_count'   => 0,
            'expires_at'     => now()->subMinutes(1),
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'otp',
            'challenge_id'    => $challenge->id,
            'otp'             => '111111',
        ], ['X-Correlation-ID' => 'otp-exp1', 'X-Idempotency-Key' => 'otp-exp-idem1']);

        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_003');
    });

})->group('identity', 'authentication', 'feature');

describe('GET /v1/auth/login/biometric/challenge', function (): void {

    it('returns a nonce for biometric challenge', function (): void {
        $response = $this->getJson('/v1/auth/login/biometric/challenge', ['X-Correlation-ID' => 'bio1']);
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data' => ['nonce', 'expires_in']]);
        expect(strlen($response->json('data.nonce')))->toBe(64); // 32 bytes hex = 64 chars
    });

})->group('identity', 'authentication', 'feature');


