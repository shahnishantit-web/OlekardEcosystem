<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 21 Abuse Test Suite (T199)
 *
 * Covers:
 * - OTP expiration enforced: expired challenge → IDENTITY_003
 * - OTP replay protection: consumed challenge cannot be reused → IDENTITY_003
 * - Max OTP attempts: correct code refused after lockout → IDENTITY_003
 * - Service token cannot be used as user Bearer → 401
 * - Service call without X-Service-Secret → 401
 * - Forged token with invalid signature → 401
 * - Service token with on_behalf_of cannot be used as user Bearer → 401
 */

beforeEach(function (): void {
    $this->capturedOtps = [];

    $this->app->bind(OtpDeliveryContract::class, fn () => new class($this) implements OtpDeliveryContract {
        public function __construct(private readonly mixed $test) {}

        public function deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult
        {
            $this->test->capturedOtps[] = $otp;
            return new OtpDeliveryResult(true, 'ref-' . uniqid(), null);
        }
    });

    Redis::connection('identity')->flushdb();
});

// ============================================================================
// OTP Abuse
// ============================================================================

describe('OTP expiration enforcement (T199)', function (): void {

    it('expired OTP challenge returns IDENTITY_003', function (): void {
        $reg = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26778100001',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'abuse-exp-1', 'X-Idempotency-Key' => 'abuse-idem-exp-1']);

        $reg->assertStatus(202);
        $challengeId = $reg->json('data.challenge_id');
        $otp         = $this->capturedOtps[0];

        // Simulate expiration by back-dating the challenge
        OtpChallenge::where('id', $challengeId)
            ->update(['expires_at' => now()->subSeconds(10)]);

        $verify = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ], ['X-Correlation-ID' => 'abuse-exp-2', 'X-Idempotency-Key' => 'abuse-idem-exp-2']);

        $verify->assertStatus(401);
        expect($verify->json('error.code'))->toBe('IDENTITY_003');
        // Verify that the OTP value itself does not appear in the response
        expect($verify->getContent())->not->toContain($otp);
    });

    it('expired OTP does not disclose the OTP value in the error body', function (): void {
        $reg = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26778100002',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'abuse-exp-3', 'X-Idempotency-Key' => 'abuse-idem-exp-3']);

        $reg->assertStatus(202);
        $challengeId = $reg->json('data.challenge_id');
        $otp         = $this->capturedOtps[0];

        OtpChallenge::where('id', $challengeId)
            ->update(['expires_at' => now()->subSeconds(10)]);

        $verify = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ], ['X-Correlation-ID' => 'abuse-exp-4', 'X-Idempotency-Key' => 'abuse-idem-exp-4']);

        $body = $verify->getContent();
        expect($body)->not->toContain($otp);
        expect($body)->not->toContain('otp_hash');
    });

});

// ============================================================================
// OTP Replay
// ============================================================================

describe('OTP replay protection (T199)', function (): void {

    it('a consumed OTP challenge cannot be reused — returns IDENTITY_003', function (): void {
        $reg = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26778100010',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'abuse-replay-1', 'X-Idempotency-Key' => 'abuse-idem-replay-1']);

        $reg->assertStatus(202);
        $challengeId = $reg->json('data.challenge_id');
        $otp         = $this->capturedOtps[0];

        // First verify: succeeds and consumes the challenge
        $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ], ['X-Correlation-ID' => 'abuse-replay-2', 'X-Idempotency-Key' => 'abuse-idem-replay-2'])
            ->assertStatus(200);

        // Replay attempt: challenge is now status=verified → rejected
        $replay = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $otp,
        ], ['X-Correlation-ID' => 'abuse-replay-3', 'X-Idempotency-Key' => 'abuse-idem-replay-3']);

        $replay->assertStatus(401);
        expect($replay->json('error.code'))->toBe('IDENTITY_003');
    });

});

// ============================================================================
// Max OTP Attempts
// ============================================================================

describe('Max OTP attempt enforcement (T199)', function (): void {

    it('correct OTP is rejected after max failed attempts lock the challenge', function (): void {
        $reg = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26778100020',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'abuse-max-1', 'X-Idempotency-Key' => 'abuse-idem-max-1']);

        $reg->assertStatus(202);
        $challengeId = $reg->json('data.challenge_id');
        $correctOtp  = $this->capturedOtps[0];

        $maxAttempts = app(ConfigurationSecurityService::class)->getOtpMaxAttempts();

        // Exhaust all attempts with an incorrect code
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/otp/verify', [
                'challenge_id' => $challengeId,
                'otp'          => '000000',
            ], [
                'X-Correlation-ID' => "abuse-max-loop-{$i}",
                'X-Idempotency-Key' => "abuse-idem-loop-{$i}",
            ])->assertStatus(401);
        }

        // Challenge is now locked (status=expired); even the correct OTP is refused
        $locked = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $correctOtp,
        ], ['X-Correlation-ID' => 'abuse-max-correct', 'X-Idempotency-Key' => 'abuse-idem-max-correct']);

        $locked->assertStatus(401);
        expect($locked->json('error.code'))->toBe('IDENTITY_003');
    });

    it('max-attempts response does not expose the correct OTP or attempt count detail', function (): void {
        $reg = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26778100021',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'abuse-max-pii-1', 'X-Idempotency-Key' => 'abuse-idem-pii-1']);

        $reg->assertStatus(202);
        $challengeId = $reg->json('data.challenge_id');
        $correctOtp  = $this->capturedOtps[0];

        $maxAttempts = app(ConfigurationSecurityService::class)->getOtpMaxAttempts();

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/otp/verify', [
                'challenge_id' => $challengeId,
                'otp'          => '000000',
            ], [
                'X-Correlation-ID' => "abuse-pii-loop-{$i}",
                'X-Idempotency-Key' => "abuse-pii-idem-{$i}",
            ]);
        }

        $response = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $correctOtp,
        ], ['X-Correlation-ID' => 'abuse-pii-final', 'X-Idempotency-Key' => 'abuse-idem-pii-final']);

        $body = $response->getContent();
        expect($body)->not->toContain($correctOtp);
        expect($body)->not->toContain('attempt_count');
        expect($body)->not->toContain('otp_hash');
        expect($body)->not->toContain('+26778100021');
    });

});

// ============================================================================
// Token Abuse
// ============================================================================

describe('Service token abuse (T199)', function (): void {

    it('service token cannot be used as a user Bearer on an identity.auth protected endpoint', function (): void {
        $resp = $this->postJson('/v1/auth/service/token', [
            'service_id' => 'abuse-svc-001',
            'audience'   => 'test-aud',
            'scopes'     => ['payments:read'],
        ], [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);

        $resp->assertStatus(200);
        $serviceJwt = $resp->json('data.access_token');

        // Service JWT has actor_type=SERVICE and a non-user audience.
        // The IdentityAuthMiddleware must reject it (aud mismatch + no session_id).
        test()->withToken($serviceJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('service call without X-Service-Secret header returns 401 with IDENTITY_002', function (): void {
        $resp = $this->postJson('/v1/auth/service/token', [
            'service_id' => 'abuse-svc-002',
            'audience'   => 'test-aud',
            'scopes'     => ['payments:read'],
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
            // X-Service-Secret intentionally omitted
        ]);

        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

    it('service call with wrong X-Service-Secret returns 401 with IDENTITY_002', function (): void {
        $resp = $this->postJson('/v1/auth/service/token', [
            'service_id' => 'abuse-svc-003',
            'audience'   => 'test-aud',
            'scopes'     => ['payments:read'],
        ], [
            'X-Service-Secret' => 'wrong-secret-value',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);

        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

    it('service token with on_behalf_of cannot be used as user Bearer on authenticated endpoints', function (): void {
        $resp = $this->postJson('/v1/auth/service/token', [
            'service_id'   => 'abuse-svc-004',
            'audience'     => 'test-aud',
            'scopes'       => ['transfer:initiate'],
            'on_behalf_of' => 'some-user-id-being-delegated',
        ], [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);

        $resp->assertStatus(200);
        $delegatedJwt = $resp->json('data.access_token');

        // Delegation does not grant user Bearer access — on_behalf_of is not a user JWT
        test()->withToken($delegatedJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

});

// ============================================================================
// Forged Token Abuse
// ============================================================================

describe('Forged Bearer token abuse (T199)', function (): void {

    it('JWT with invalid ES256 signature is rejected with 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode([
            'alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'aabbccddeeff',
        ])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'forge-user-001',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('JWT forged with wrong issuer is rejected with 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode([
            'alg' => 'ES256', 'typ' => 'JWT',
        ])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'forge-user-002',
            'iss'        => 'https://evil-issuer.example.com',
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('JWT forged with wrong audience is rejected with 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode([
            'alg' => 'ES256', 'typ' => 'JWT',
        ])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'forge-user-003',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => 'https://attacker.example.com',
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('completely malformed token is rejected with 401', function (): void {
        test()->withToken('not-a-jwt-at-all')
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

})->group('identity', 'security', 'abuse');
