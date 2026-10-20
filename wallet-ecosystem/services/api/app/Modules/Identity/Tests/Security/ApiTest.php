<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 21 API Security Test Suite (T201 / T237)
 *
 * Covers:
 * - All identity.auth protected endpoints reject unauthenticated requests
 * - Error responses do not include stack traces or internal file paths
 * - Rate limit headers present on throttled public endpoints (T237)
 * - Idempotency: duplicate requests with the same Idempotency-Key return the same response
 */

beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $ch, string $c): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });

    Redis::connection('identity')->flushdb();
});

// ============================================================================
// Helpers
// ============================================================================

function apiSecCreateAndLogin(mixed $test, string $mobile, string $fp = 'api-sec-dev'): array
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
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('111222'),
        'status'          => 'active',
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $fp),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    $resp = $test->postJson('/v1/auth/login', [
        'identifier'         => $mobile,
        'credential_type'    => 'pin',
        'credential'         => '111222',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);

    return [
        'access_token'  => (string) $resp->json('data.access_token'),
        'refresh_token' => (string) $resp->json('data.refresh_token'),
        'session_id'    => (string) $resp->json('data.session_id'),
        'user_id'       => $user->id,
    ];
}

// ============================================================================
// Authentication Required
// ============================================================================

describe('All identity.auth endpoints require a valid Bearer token (T201)', function (): void {

    it('GET /v1/auth/sessions returns 401 without Bearer token', function (): void {
        test()->getJson('/v1/auth/sessions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/logout returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('DELETE /v1/auth/sessions returns 401 without Bearer token', function (): void {
        test()->deleteJson('/v1/auth/sessions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/devices/register returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/devices/register', [
            'device_fingerprint' => 'test-fp',
            'platform'           => 'android',
        ])->assertStatus(401)
          ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('GET /v1/auth/devices returns 401 without Bearer token', function (): void {
        test()->getJson('/v1/auth/devices')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/step-up/initiate returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 1000, 'currency' => 'BWP'],
        ])->assertStatus(401)
          ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/step-up/verify returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => 'invalid',
            'method'        => 'pin',
            'credential'    => '123456',
        ])->assertStatus(401)
          ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/pin/change returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/pin/change', [
            'current_pin' => '123456',
            'new_pin'     => '654321',
        ])->assertStatus(401)
          ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/mfa/enroll/totp returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/mfa/enroll/totp')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('POST /v1/auth/mfa/disable returns 401 without Bearer token', function (): void {
        test()->postJson('/v1/auth/mfa/disable')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'IDENTITY_022');
    });

    it('401 responses include the IDENTITY_022 error code (not a generic 401)', function (): void {
        $response = test()->getJson('/v1/auth/sessions');

        $response->assertStatus(401);
        expect($response->json('success'))->toBeFalse();
        expect($response->json('error.code'))->toBe('IDENTITY_022');
        expect($response->json('error.message'))->not->toBeNull();
    });

});

// ============================================================================
// Error Response Safety
// ============================================================================

describe('Error responses do not expose internal details (T201)', function (): void {

    it('login failure does not include a stack trace in the response body', function (): void {
        $resp = test()->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779901001',
            'credential'      => '000000',
        ]);

        $body = $resp->getContent();
        expect($body)->not->toContain('Stack trace');
        expect($body)->not->toContain('#0 ');
        expect($body)->not->toContain('Throwable');
        expect($body)->not->toContain('Exception in');
    });

    it('validation failure does not include internal file paths in the response body', function (): void {
        // Send an invalid registration request to trigger validation error
        $resp = test()->postJson('/v1/auth/register', [
            'mobile_number' => 'not-a-phone',
            'actor_type'    => 'CUSTOMER',
        ]);

        $body = $resp->getContent();
        // Internal paths must not be exposed
        expect($body)->not->toContain('/var/www');
        expect($body)->not->toContain('/app/Modules');
        expect($body)->not->toContain('.php:');
    });

    it('authentication error response is structurally safe (no PII in error body)', function (): void {
        $submittedPin = '876543';

        $resp = test()->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779901002',
            'credential'      => $submittedPin,
        ]);

        $body = $resp->getContent();
        expect($body)->not->toContain($submittedPin);
        expect($body)->not->toContain('+26779901002');
    });

    it('401 error response does not include stack trace or internal paths', function (): void {
        $resp = test()->withToken('not-a-valid-token')
            ->getJson('/v1/auth/sessions');

        $resp->assertStatus(401);
        $body = $resp->getContent();

        expect($body)->not->toContain('Stack trace');
        expect($body)->not->toContain('.php:');
        expect($body)->not->toContain('/var/www');
    });

});

// ============================================================================
// Rate Limit Headers (T237)
// ============================================================================

describe('Rate limit headers present on public endpoints (T237)', function (): void {

    /**
     * The identity-public rate limiter is registered via RateLimiter::for() in
     * IdentityServiceProvider::boot() and applied to public routes including login.
     * Laravel emits X-RateLimit-Limit and X-RateLimit-Remaining on every throttled response.
     */
    it('X-RateLimit-Limit and X-RateLimit-Remaining headers are present on public endpoints', function (): void {
        $response = test()->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779902001',
            'credential'      => '000000',
        ]);

        expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
        expect($response->headers->has('X-RateLimit-Remaining'))->toBeTrue();
    });

});

// ============================================================================
// Idempotency
// ============================================================================

describe('Idempotency prevents duplicate state changes (T201)', function (): void {

    it('duplicate POST /v1/auth/service/token with same Idempotency-Key returns replayed response', function (): void {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'service_id' => 'api-sec-svc-001',
            'audience'   => 'test-aud',
            'scopes'     => ['payments:read'],
        ];
        $headers = [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => $idempotencyKey,
        ];

        $first  = test()->postJson('/v1/auth/service/token', $payload, $headers);
        $second = test()->postJson('/v1/auth/service/token', $payload, $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Replayed response must carry the Idempotency-Replay header
        expect($second->headers->get('Idempotency-Replay'))->toBe('true');

        // Both responses must return the same access token
        expect($second->json('data.access_token'))->toBe($first->json('data.access_token'));
    });

    it('login does not carry IdempotencyMiddleware — HTTP-level replay header is not present on login responses', function (): void {
        // The /v1/auth/login route does not have IdempotencyMiddleware applied.
        // HTTP-level idempotency replay (Idempotency-Replay header) is only
        // provided by routes that explicitly include IdempotencyMiddleware
        // (e.g., /v1/auth/service/token, /admin/platform/configuration/security/{key}).
        // Login uses X-Idempotency-Key for internal application-layer deduplication only.
        apiSecCreateAndLogin(test(), '+26779903001', 'api-idem-dev');

        $resp = test()->postJson('/v1/auth/login', [
            'identifier'         => '+26779903001',
            'credential_type'    => 'pin',
            'credential'         => '111222',
            'device_fingerprint' => 'api-idem-dev',
        ], ['X-Idempotency-Key' => (string) Str::uuid()]);

        $resp->assertStatus(200);
        // Idempotency-Replay header is not present because the route lacks IdempotencyMiddleware
        expect($resp->headers->has('Idempotency-Replay'))->toBeFalse();
    })->note('login lacks IdempotencyMiddleware — HTTP replay is not active on this endpoint');

    it('different Idempotency-Keys on the same endpoint create independent responses', function (): void {
        apiSecCreateAndLogin(test(), '+26779903002', 'api-idem-dev2');

        $loginPayload = [
            'identifier'         => '+26779903002',
            'credential_type'    => 'pin',
            'credential'         => '111222',
            'device_fingerprint' => 'api-idem-dev2',
        ];

        $resp1 = test()->postJson('/v1/auth/login', $loginPayload, ['X-Idempotency-Key' => (string) Str::uuid()]);
        $resp2 = test()->postJson('/v1/auth/login', $loginPayload, ['X-Idempotency-Key' => (string) Str::uuid()]);

        $resp1->assertStatus(200);
        $resp2->assertStatus(200);

        // Different keys → different sessions (not replayed)
        expect($resp2->json('data.session_id'))->not->toBe($resp1->json('data.session_id'));
        expect($resp2->headers->has('Idempotency-Replay'))->toBeFalse();
    });

})->group('identity', 'security', 'api');
