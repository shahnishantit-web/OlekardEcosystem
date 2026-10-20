<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 9 Device Verification Tests â€” T101 (AC-009)
 *
 * Verifies that:
 * - Login from an unrecognised device returns IDENTITY_023 with a challenge_id
 * - Submitting the correct OTP to POST /v1/auth/devices/verify promotes the device to
 *   trusted and returns full session tokens
 * - A trusted device can subsequently log in without a verification challenge
 * - Invalid OTP returns IDENTITY_003
 * - Missing fields return 422
 * - Identity.Device.Verified audit event is recorded
 */

/** Captures the last OTP delivered so tests can submit it. */
$capturedOtp = null;

beforeEach(function () use (&$capturedOtp): void {
    $capturedOtp = null;

    $this->app->bind(OtpDeliveryContract::class, function () use (&$capturedOtp) {
        return new class($capturedOtp) implements OtpDeliveryContract {
            public function __construct(private mixed &$capturedOtp) {}

            public function deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult
            {
                $this->capturedOtp = $otp;
                return new OtpDeliveryResult(true, 'stub-' . uniqid(), null);
            }
        };
    });

    Redis::connection('identity')->flushdb();
});

/** Create a user with a PIN credential but NO trusted device. */
function dvtCreateUser(string $mobile): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
    ]);

    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('123456'),
        'status'          => 'active',
    ]);

    return ['user' => $user];
}

describe('New device login â€” IDENTITY_023 (AC-009)', function () use (&$capturedOtp): void {

    it('returns 403 IDENTITY_023 with challenge_id when device is untrusted', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200001');

        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200001',
            'credential'         => '123456',
            'device_fingerprint' => 'new-device-001',
        ]);

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('IDENTITY_023');
        expect($response->json('error.challenge_id'))->not->toBeNull();
        expect($capturedOtp)->not->toBeNull(); // OTP was sent
    });

    it('does NOT return access tokens on the IDENTITY_023 response', function (): void {
        dvtCreateUser('+26771200002');

        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200002',
            'credential'         => '123456',
            'device_fingerprint' => 'new-device-002',
        ]);

        $response->assertStatus(403);
        expect($response->json('data'))->toBeNull();
        expect($response->getContent())->not->toContain('access_token');
        expect($response->getContent())->not->toContain('refresh_token');
    });

});

describe('POST /v1/auth/devices/verify â€” happy path', function () use (&$capturedOtp): void {

    it('valid OTP promotes device to trusted and returns session tokens', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200010');

        // Step 1: login with new device â†’ 403 + challenge_id + OTP sent
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200010',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-001',
        ]);

        $loginResp->assertStatus(403);
        $challengeId = $loginResp->json('error.challenge_id');
        $otp         = $capturedOtp;

        expect($challengeId)->not->toBeNull();
        expect($otp)->not->toBeNull();

        // Step 2: verify device
        $verifyResp = $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ]);

        $verifyResp->assertStatus(200);
        $verifyResp->assertJsonStructure(['success', 'data' => [
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id',
        ]]);
        expect($verifyResp->json('success'))->toBeTrue();
    });

    it('device.status is trusted in DB after successful verification', function () use (&$capturedOtp): void {
        $users   = app(UserRepositoryInterface::class);
        $devices = app(DeviceRepositoryInterface::class);

        dvtCreateUser('+26771200011');
        $user = $users->findByMobile('+26771200011');

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200011',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-002',
        ]);

        $loginResp->assertStatus(403);
        $challengeId = $loginResp->json('error.challenge_id');

        $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $capturedOtp,
        ])->assertStatus(200);

        $device = $devices->findByUserAndFingerprint($user->id, hash('sha256', 'verify-dev-002'));
        expect($device)->not->toBeNull();
        expect($device->status)->toBe('trusted');
        expect($device->trusted_at)->not->toBeNull();
    });

    it('second login with same device succeeds without verification (device is now trusted)', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200012');

        // First login â†’ verify device
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200012',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-003',
        ]);
        $loginResp->assertStatus(403);

        $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $loginResp->json('error.challenge_id'),
            'otp'          => $capturedOtp,
        ])->assertStatus(200);

        // Second login with same device â€” no verification required
        $r2 = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200012',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-003',
        ]);

        $r2->assertStatus(200);
        $r2->assertJsonStructure(['success', 'data' => ['access_token', 'session_id']]);
    });

    it('OTP challenge is consumed â€” cannot reuse the same OTP', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200013');

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200013',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-004',
        ]);

        $challengeId = $loginResp->json('error.challenge_id');
        $otp         = $capturedOtp;

        // First verification: succeeds
        $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ])->assertStatus(200);

        // Second verification with same challenge: rejected (challenge consumed or Redis key deleted)
        $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ])->assertStatus(401);
    });

});

describe('POST /v1/auth/devices/verify â€” failure paths', function () use (&$capturedOtp): void {

    it('invalid OTP returns 401 IDENTITY_003', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200020');

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200020',
            'credential'         => '123456',
            'device_fingerprint' => 'verify-dev-010',
        ]);

        $loginResp->assertStatus(403);
        $challengeId = $loginResp->json('error.challenge_id');

        $verifyResp = $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $challengeId,
            'otp'          => '000000', // wrong OTP
        ]);

        $verifyResp->assertStatus(401);
        expect($verifyResp->json('error.code'))->toBe('IDENTITY_003');
    });

    it('unknown challenge_id returns 401', function (): void {
        $response = $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
            'otp'          => '123456',
        ]);

        $response->assertStatus(401);
    });

    it('missing challenge_id returns 422', function (): void {
        $response = $this->postJson('/v1/auth/devices/verify', [
            'otp' => '123456',
        ]);

        $response->assertStatus(422);
    });

    it('missing otp returns 422', function (): void {
        $response = $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertStatus(422);
    });

    it('wrong credentials before new device â†’ IDENTITY_002 not IDENTITY_023', function (): void {
        dvtCreateUser('+26771200030');

        $response = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200030',
            'credential'         => '999999', // wrong PIN
            'device_fingerprint' => 'verify-dev-020',
        ]);

        // Wrong credentials fail before device check
        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_002');
    });

});

describe('Audit events â€” T099', function () use (&$capturedOtp): void {

    it('identity.device.new-device-detected audit event recorded after device trust (T099)', function () use (&$capturedOtp): void {
        dvtCreateUser('+26771200040');

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771200040',
            'credential'         => '123456',
            'device_fingerprint' => 'audit-dev-001',
        ]);

        $loginResp->assertStatus(403);

        $verifyResp = $this->postJson('/v1/auth/devices/verify', [
            'challenge_id' => $loginResp->json('error.challenge_id'),
            'otp'          => $capturedOtp,
        ]);

        $verifyResp->assertStatus(200);

        // Audit event must be recorded (platform.audit_events table)
        $auditRecord = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.new-device-detected')
            ->first();

        expect($auditRecord)->not->toBeNull();
        expect($auditRecord->outcome)->toBe('success');
    });

});


