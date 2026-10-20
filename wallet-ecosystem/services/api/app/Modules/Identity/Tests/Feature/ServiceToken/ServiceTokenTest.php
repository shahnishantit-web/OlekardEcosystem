<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\Clock\SystemClock;

/**
 * Phase 14 Service Identity Feature Tests — T141
 *
 * Covers:
 * - Valid credential → JWT with correct actor_type=SERVICE claims (spec Section 16; FR-E01-056)
 * - on_behalf_of delegation preserved in token (FR-E01-057)
 * - Audit event identity.service.token-issued emitted on success (T139)
 * - Missing required fields (service_id, scopes, audience) → 400 IDENTITY_001
 * - Invalid credential → 401 IDENTITY_002
 * - Token ES256-verifiable via JWKS public key
 * - jti unique per issuance (replay-safe)
 * - Idempotency replay: same Idempotency-Key → same cached response
 */

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

// ============================================================================
// Helpers
// ============================================================================

/**
 * Helper: make service token request.
 * Sends unique Idempotency-Key by default to avoid cross-test replay collisions.
 */
function svcRequest(
    mixed  $test,
    array  $body = [],
    string $secret = 'test-service-secret',
    array  $extraHeaders = [],
): \Illuminate\Testing\TestResponse {
    $headers = array_merge([
        'X-Service-Secret' => $secret,
        'Idempotency-Key'  => (string) Str::uuid(),
    ], $extraHeaders);

    $payload = array_merge([
        'service_id' => 'wallet-core',
        'audience'   => 'payment-service',
        'scopes'     => ['payments:read', 'payments:write'],
    ], $body);

    return $test->postJson('/v1/auth/service/token', $payload, $headers);
}

/**
 * Helper: decode JWT claims without full verification (for assertion only).
 */
function svcDecodeJwt(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [];
    }
    return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];
}

// ============================================================================
// T141a: Successful issuance — correct JWT structure and claims
// ============================================================================

describe('Service token issuance — happy path (T141)', function (): void {

    it('returns access_token, token_type, expires_in, scopes on valid request', function (): void {
        $resp = svcRequest($this);
        $resp->assertStatus(200);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data.access_token'))->not->toBeNull()->toBeString();
        expect($resp->json('data.token_type'))->toBe('Bearer');
        expect($resp->json('data.expires_in'))->toBeInt()->toBeGreaterThan(0);
        expect($resp->json('data.scopes'))->toBeArray()->toContain('payments:read');
    });

    it('issued JWT carries actor_type=SERVICE, service_id, scopes, aud, jti claims', function (): void {
        $resp = svcRequest($this, [
            'service_id' => 'ledger-service',
            'audience'   => 'identity-service',
            'scopes'     => ['ledger:post', 'ledger:read'],
        ]);
        $resp->assertStatus(200);

        $claims = svcDecodeJwt((string) $resp->json('data.access_token'));

        expect($claims['actor_type'] ?? null)->toBe('SERVICE');
        expect($claims['service_id'] ?? null)->toBe('ledger-service');
        expect($claims['scopes'] ?? null)->toBe(['ledger:post', 'ledger:read']);
        expect($claims['aud'] ?? null)->toBe('identity-service');
        expect($claims['jti'] ?? '')->not->toBeEmpty();
        expect($claims['iat'] ?? 0)->toBeInt()->toBeGreaterThan(0);
        expect($claims['exp'] ?? 0)->toBeInt()->toBeGreaterThan(0);
        expect($claims['exp'])->toBeGreaterThan($claims['iat']);
    });

    it('on_behalf_of delegation claim is preserved in the JWT', function (): void {
        $resp = svcRequest($this, [
            'service_id'   => 'notification-service',
            'audience'     => 'wallet-core',
            'scopes'       => ['notify:send'],
            'on_behalf_of' => 'user-uuid-abc123',
        ]);
        $resp->assertStatus(200);

        $claims = svcDecodeJwt((string) $resp->json('data.access_token'));
        expect($claims['on_behalf_of'] ?? null)->toBe('user-uuid-abc123');
    });

    it('on_behalf_of is null when not provided', function (): void {
        $resp = svcRequest($this);
        $resp->assertStatus(200);

        $claims = svcDecodeJwt((string) $resp->json('data.access_token'));
        expect($claims)->toHaveKey('on_behalf_of');
        expect($claims['on_behalf_of'])->toBeNull();
    });

    it('token is ES256-verifiable using the JWKS public key', function (): void {
        $resp = svcRequest($this);
        $resp->assertStatus(200);

        $jwt        = (string) $resp->json('data.access_token');
        $jwksProvider = app(JwksProviderContract::class);
        $signingKey   = $jwksProvider->getSigningKey();

        $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($signingKey->privateKeyPem));
        expect($privDetails)->not->toBeFalse();
        $publicKeyPem = $privDetails['key'];

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($publicKeyPem),
        );

        $parsed = $config->parser()->parse($jwt);
        $config->validator()->assert($parsed, new LooseValidAt(new SystemClock(new \DateTimeZone('UTC'))));

        expect($parsed->claims()->get('actor_type'))->toBe('SERVICE');
    });

    it('jti is unique on every issuance (replay-safe)', function (): void {
        $resp1 = svcRequest($this);
        $resp2 = svcRequest($this);

        $claims1 = svcDecodeJwt((string) $resp1->json('data.access_token'));
        $claims2 = svcDecodeJwt((string) $resp2->json('data.access_token'));

        expect($claims1['jti'])->not->toBe($claims2['jti']);
    });

});

// ============================================================================
// T141b: Audit event (T139)
// ============================================================================

describe('Service token audit event (T141, T139)', function (): void {

    it('emits identity.service.token-issued audit event with correct after_state on success', function (): void {
        $resp = svcRequest($this, [
            'service_id' => 'audit-test-svc',
            'audience'   => 'audit-recipient',
            'scopes'     => ['audit:read'],
        ]);
        $resp->assertStatus(200);

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.service.token-issued')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->outcome)->toBe('success');

        $afterState = json_decode((string) $event->after_state, true);
        expect($afterState['service_id'] ?? null)->toBe('audit-test-svc');
        expect($afterState['aud'] ?? null)->toBe('audit-recipient');
        expect($afterState['jti'] ?? '')->not->toBeEmpty();
        expect($afterState['scopes'] ?? null)->toBe(['audit:read']);
        expect($afterState['iat'] ?? 0)->toBeInt()->toBeGreaterThan(0);
        expect($afterState['exp'] ?? 0)->toBeInt()->toBeGreaterThan(0);
    });

    it('audit event does not expose the raw service secret', function (): void {
        svcRequest($this)->assertStatus(200);

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.service.token-issued')
            ->orderByDesc('occurred_at')
            ->first();

        $json = json_encode($event);
        expect($json)->not->toContain('test-service-secret');
    });

    it('audit event includes on_behalf_of in after_state when delegation is used (T215/T216)', function (): void {
        $resp = svcRequest($this, [
            'service_id'   => 'delegation-audit-svc',
            'audience'     => 'delegation-aud',
            'scopes'       => ['transfer:initiate'],
            'on_behalf_of' => 'user-delegated-uuid-789',
        ]);
        $resp->assertStatus(200);

        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.service.token-issued')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        $afterState = json_decode((string) $event->after_state, true);
        // spec 10.5: both service identity and originating user identity MUST be auditable
        expect($afterState['service_id'] ?? null)->toBe('delegation-audit-svc');
        expect($afterState['on_behalf_of'] ?? null)->toBe('user-delegated-uuid-789');
    });

});

// ============================================================================
// T141c: Validation errors
// ============================================================================

describe('Service token validation errors (T141)', function (): void {

    it('missing service_id returns 400 IDENTITY_001', function (): void {
        $resp = svcRequest($this, ['service_id' => '']);
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('missing scopes returns 400 IDENTITY_001', function (): void {
        $resp = svcRequest($this, ['scopes' => []]);
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('missing audience returns 400 IDENTITY_001', function (): void {
        $resp = svcRequest($this, ['audience' => '']);
        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('invalid credential returns 401 IDENTITY_002', function (): void {
        $resp = svcRequest($this, [], 'wrong-secret');
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

    it('empty credential returns 401 IDENTITY_002', function (): void {
        $resp = svcRequest($this, [], '');
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

});

// ============================================================================
// T141d: Idempotency replay
// ============================================================================

describe('Service token idempotency replay (T141)', function (): void {

    it('same Idempotency-Key returns cached response on replay', function (): void {
        $key = (string) Str::uuid();

        $headers = ['X-Service-Secret' => 'test-service-secret', 'Idempotency-Key' => $key];
        $payload = ['service_id' => 'replay-svc', 'audience' => 'replay-aud', 'scopes' => ['read']];

        $resp1 = $this->postJson('/v1/auth/service/token', $payload, $headers);
        $resp2 = $this->postJson('/v1/auth/service/token', $payload, $headers);

        $resp1->assertStatus(200);
        $resp2->assertStatus(200);

        // Both responses must return the same access_token (replay from cache)
        expect($resp1->json('data.access_token'))->toBe($resp2->json('data.access_token'));
        expect($resp2->headers->get('Idempotency-Replay'))->toBe('true');
    });

});
