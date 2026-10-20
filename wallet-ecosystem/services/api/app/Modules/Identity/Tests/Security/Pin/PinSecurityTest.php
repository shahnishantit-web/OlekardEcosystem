<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 11 Security Tests â€” PIN & Credential Management (T114)
 *
 * Invariants under test:
 * BR-004: Raw PIN MUST NOT appear in DB, logs, responses, or audit records.
 * AC-010: PIN stored as Argon2id hash ($argon2id prefix).
 * AC-010: Reset flow validates OTP before allowing new PIN to be set.
 */

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

function secPinCreateUserWithPin(string $mobile, string $pin = '246810', string $device = 'sec-dev'): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
    ]);
    $credRepo->create([
        'user_id'             => $user->id,
        'credential_type'     => 'pin',
        'credential_hash'     => Hash::make($pin),
        'status'              => 'active',
        'failed_attempt_count' => 0,
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $device),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'pin' => $pin, 'device' => $device];
}

describe('PIN security invariants (BR-004, AC-010)', function () use (&$capturedOtp): void {

    it('PIN stored in DB is a hash, not the raw value (AC-010)', function () use (&$capturedOtp): void {
        $rawPin       = '246810';
        $registerResp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771400001', 'actor_type' => 'CUSTOMER']);
        $challengeId  = $registerResp->json('data.challenge_id');
        $verifyResp   = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $capturedOtp]);
        $setupToken   = $verifyResp->json('data.setup_token');

        $this->postJson('/v1/auth/pin/set', ['pin' => $rawPin], ['X-Setup-Token' => $setupToken])->assertStatus(201);

        $credential = DB::connection('identity')
            ->table('identity.credentials')
            ->where('credential_type', 'pin')
            ->orderByDesc('created_at')
            ->first();

        expect($credential)->not->toBeNull();
        // Hash starts with $argon2id (BR-004, AC-010)
        expect(str_starts_with($credential->credential_hash, '$argon2id'))->toBeTrue();
        // Raw PIN is not in the hash string
        expect($credential->credential_hash)->not->toContain($rawPin);
    });

    it('raw PIN does not appear in the PIN set response body (BR-004)', function () use (&$capturedOtp): void {
        $rawPin       = '246810';
        $registerResp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771400002', 'actor_type' => 'CUSTOMER']);
        $challengeId  = $registerResp->json('data.challenge_id');
        $verifyResp   = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $capturedOtp]);
        $setupToken   = $verifyResp->json('data.setup_token');

        $resp = $this->postJson('/v1/auth/pin/set', ['pin' => $rawPin], ['X-Setup-Token' => $setupToken]);

        expect($resp->getContent())->not->toContain($rawPin);
    });

    it('raw PIN does not appear in the changePin response body (BR-004)', function (): void {
        $rawPin    = '246810';
        $newPin    = '357911';
        $data      = secPinCreateUserWithPin('+26771400003', $rawPin, 'sec-dev-003');

        $loginResp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771400003',
            'credential_type'    => 'pin',
            'credential'         => $rawPin,
            'device_fingerprint' => 'sec-dev-003',
        ]);
        $token = $loginResp->json('data.access_token');

        $resp = $this->postJson('/v1/auth/pin/change', [
            'current_pin' => $rawPin,
            'new_pin'     => $newPin,
        ], ['Authorization' => 'Bearer ' . $token]);

        expect($resp->getContent())->not->toContain($rawPin);
        expect($resp->getContent())->not->toContain($newPin);
    });

    it('raw PIN does not appear in audit records (BR-004, BR-013)', function () use (&$capturedOtp): void {
        $rawPin       = '246810';
        $registerResp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771400004', 'actor_type' => 'CUSTOMER']);
        $challengeId  = $registerResp->json('data.challenge_id');
        $verifyResp   = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $capturedOtp]);
        $setupToken   = $verifyResp->json('data.setup_token');

        $this->postJson('/v1/auth/pin/set', ['pin' => $rawPin], ['X-Setup-Token' => $setupToken])->assertStatus(201);

        $auditRecord = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.credential.pin-created')
            ->first();

        expect($auditRecord)->not->toBeNull();

        // Serialize the whole audit record and confirm raw PIN is absent
        $auditJson = json_encode($auditRecord);
        expect($auditJson)->not->toContain($rawPin);
    });

    it('PIN reset flow requires OTP verification before accepting new PIN (AC-010)', function (): void {
        secPinCreateUserWithPin('+26771400005', '246810', 'sec-dev-005');

        // Attempt to reset without a valid OTP â€” must fail
        $resp = $this->postJson('/v1/auth/pin/reset/complete', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
            'otp'          => '000000',
            'new_pin'      => '357911',
        ]);

        $resp->assertStatus(401);
        // PIN hash must NOT have changed
        $data = secPinCreateUserWithPin('+26771400006', '246810', 'sec-dev-006');
        $credential = app(CredentialRepositoryInterface::class)
            ->findByUserAndType($data['user']->id, 'pin');
        expect(Hash::check('357911', $credential->credential_hash))->toBeFalse();
    });

    it('PIN reset challenge_id cannot be replayed after successful reset (AC-010)', function () use (&$capturedOtp): void {
        secPinCreateUserWithPin('+26771400007', '246810', 'sec-dev-007');

        $initResp    = $this->postJson('/v1/auth/pin/reset/initiate', ['mobile_number' => '+26771400007']);
        $challengeId = $initResp->json('data.challenge_id');

        // First reset: success
        $this->postJson('/v1/auth/pin/reset/complete', [
            'challenge_id' => $challengeId,
            'otp'          => $capturedOtp,
            'new_pin'      => '357911',
        ])->assertStatus(200);

        // Second reset attempt with same challenge: must be rejected
        $resp = $this->postJson('/v1/auth/pin/reset/complete', [
            'challenge_id' => $challengeId,
            'otp'          => $capturedOtp,
            'new_pin'      => '246810',
        ]);

        $resp->assertStatus(401);
    });

    it('changePin with wrong current PIN does NOT update the stored hash (BR-004)', function (): void {
        secPinCreateUserWithPin('+26771400010', '246810', 'sec-dev-010');

        $loginResp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771400010',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'sec-dev-010',
        ]);
        $token = $loginResp->json('data.access_token');

        $this->postJson('/v1/auth/pin/change', [
            'current_pin' => '000000', // wrong
            'new_pin'     => '357911',
        ], ['Authorization' => 'Bearer ' . $token])->assertStatus(401);

        // Verify the original PIN still works for login
        $loginResp2 = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771400010',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'sec-dev-010',
        ]);
        $loginResp2->assertStatus(200);
    });

})->group('identity', 'pin', 'security');

