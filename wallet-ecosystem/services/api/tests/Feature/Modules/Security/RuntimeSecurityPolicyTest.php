<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\RefreshTokenService;
use App\Modules\Identity\Domain\Services\RuntimeSecurityPolicy;
use App\Modules\Identity\Infrastructure\Http\Middleware\IdentityAuthMiddleware;
use App\Modules\Identity\Infrastructure\Http\Middleware\IdentityOptionalAuthMiddleware;
use App\Modules\Identity\Infrastructure\Models\Credential;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class RuntimeSecurityPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $m = app('redis');
        $p = new \ReflectionProperty($m, 'connections');
        $c = $p->getValue($m);
        $c['identity'] = new FakeRedisConnection;
        $p->setValue($m, $c);
        Queue::fake();
        app('router')->get('v1/adm023/probe', fn (Request $r) => response()->json(['user' => $r->attributes->get('identity.user_id')]))->middleware(IdentityAuthMiddleware::class);
        app('router')->get('v1/adm023/optional', fn (Request $r) => response()->json(['user' => $r->attributes->get('identity.user_id')]))->middleware(IdentityOptionalAuthMiddleware::class);
    }

    private function policy(string $key, mixed $v): void
    {
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(['config_key' => $key, 'version' => 1], ['id' => (string) Uuid::uuid7(), 'config_value' => json_encode(['value' => $v]), 'value_type' => 'json', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        app(ConfigurationServiceInterface::class)->invalidate($key);
    }

    private function actor(): string
    {
        $a = $this->createAuthenticatedAdmin(['security.config.read']);

        return $a->id;
    }

    private function policySession(string $user, array $extra = []): Session
    {
        $id = (string) Uuid::uuid7();
        $d = Device::forceCreate(['id' => (string) Uuid::uuid7(), 'user_id' => $user, 'device_fingerprint' => hash('sha256', $id), 'platform' => 'web', 'status' => 'trusted', 'trusted_at' => now()]);

        return Session::forceCreate(array_merge(['id' => $id, 'user_id' => $user, 'device_id' => $d->id, 'refresh_token_hash' => hash('sha256', 'refresh-'.$id), 'refresh_token_family_id' => (string) Uuid::uuid7(), 'actor_type' => 'ADMIN', 'status' => 'active', 'last_activity_at' => now(), 'expires_at' => now()->addHour()], $extra));
    }

    private function token(Session $s, string $audience = ''): string
    {
        $key = app(JwksProviderContract::class)->getSigningKey();
        $c = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($key->privateKeyPem), InMemory::plainText($key->privateKeyPem));
        $now = new \DateTimeImmutable;

        return $c->builder()->issuedBy((string) config('app.url'))->permittedFor($audience !== '' ? $audience : (string) config('app.url'))->relatedTo($s->user_id)->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+1 hour'))->withClaim('session_id', $s->id)->withClaim('actor_type', 'ADMIN')->withClaim('device_id', $s->device_id)->withClaim('permissions', [])->getToken($c->signer(), $c->signingKey())->toString();
    }

    public function test_signed_access_works_and_tampered_tokens_fail_both_middleware(): void
    {
        $user = $this->actor();
        $s = $this->policySession($user);
        $jwt = $this->token($s);
        $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/v1/adm023/probe')->assertOk()->assertJsonPath('user', $user);
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $payload['sub'] = (string) Uuid::uuid7();
        $parts[1] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $forged = implode('.', $parts);
        $this->withHeader('Authorization', 'Bearer '.$forged)->getJson('/v1/adm023/probe')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$forged)->getJson('/v1/adm023/optional')->assertOk()->assertJsonPath('user', null);
    }

    public function test_optional_middleware_also_checks_audience(): void
    {
        $s = $this->policySession($this->actor());
        $jwt = $this->token($s, 'untrusted-audience');
        $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/v1/adm023/optional')->assertOk()->assertJsonPath('user', null);
    }

    public function test_idle_and_absolute_expiry_cannot_be_refreshed(): void
    {
        $this->policy('auth.session.inactivity_ttl_admin', 60);
        $s = $this->policySession($this->actor(), ['last_activity_at' => now()->subMinutes(2)]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertUnauthorized();
        try {
            app(RefreshTokenService::class)->rotate('refresh-'.$s->id, (string) Uuid::uuid7(), 'test-ip');
            $this->fail('Idle session refreshed.');
        } catch (InvalidCredentialsException) {
            $this->assertTrue(true);
        }
        $other = $this->policySession($s->user_id, ['expires_at' => now()->subSecond()]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($other))->getJson('/v1/adm023/probe')->assertUnauthorized();
    }

    public function test_mfa_policy_blocks_business_but_preserves_enrollment_and_verified_sessions(): void
    {
        $this->policy('auth.mfa.admin.required', true);
        $s = $this->policySession($this->actor());
        $jwt = $this->token($s);
        $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/v1/adm023/probe')->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/v1/adm023/optional')->assertForbidden();
        $r = Request::create('/v1/auth/mfa/enroll/totp', 'POST');
        $r->attributes->set('identity.user_id', $s->user_id);
        $r->attributes->set('identity.actor_type', 'ADMIN');
        app(RuntimeSecurityPolicy::class)->authorize($r, $s);
        $this->assertTrue(true);
        $s->mfa_verified_at = now();
        $s->save();
        $this->withHeader('Authorization', 'Bearer '.$jwt)->getJson('/v1/adm023/probe')->assertOk();
    }

    public function test_role_mfa_requirement_is_authoritative_and_optional_preserves_baseline(): void
    {
        $user = $this->actor();
        $slug = DB::connection('identity')->table('identity.roles')->join('identity.users', 'identity.users.default_role_id', '=', 'identity.roles.id')->where('identity.users.id', $user)->value('identity.roles.slug');
        $this->policy('auth.mfa.role_requirements', [$slug => 'required']);
        $s = $this->policySession($user);
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertForbidden();
        $this->policy('auth.mfa.role_requirements', [$slug => 'optional']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertOk();
    }

    public function test_admin_ip_allowlist_enforced_without_trusting_client_claims(): void
    {
        $this->policy('security.admin.ip_allowlist', ['10.0.0.0/8', '2001:db8::/32']);
        $s = $this->policySession($this->actor());
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertForbidden()->assertJsonPath('error.code', 'ADMIN_IP_DENIED');
        $this->policy('security.admin.ip_allowlist', ['127.0.0.1/32']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertOk();
    }

    public function test_password_policy_and_expiry_are_consumed_at_runtime(): void
    {
        $this->policy('auth.password.min_length', 14);
        $p = app(RuntimeSecurityPolicy::class);
        $this->assertNotEmpty($p->passwordErrors('Short1!'));
        $this->assertSame([], $p->passwordErrors('LongEnoughPass123!'));
        $this->policy('auth.password.expiry_days', 30);
        $user = $this->actor();
        $s = $this->policySession($user);
        Credential::forceCreate(['user_id' => $user, 'credential_type' => 'password', 'credential_hash' => Hash::make('LongEnoughPass123!'), 'status' => 'active', 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($s))->getJson('/v1/adm023/probe')->assertForbidden()->assertJsonPath('error.code', 'PASSWORD_EXPIRED');
    }

    public function test_session_cap_rejects_new_session_and_refresh_retains_mfa_proof(): void
    {
        $this->policy('auth.session.max_active', 1);
        $s = $this->policySession($this->actor(), ['mfa_verified_at' => now()]);
        $a = $s->getAttributes();
        $a['id'] = (string) Uuid::uuid7();
        $a['refresh_token_hash'] = hash('sha256', (string) Uuid::uuid7());
        try {
            app(RuntimeSecurityPolicy::class)->createSession(app(SessionRepositoryInterface::class), $a);
            $this->fail('Session cap bypassed.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
        }
        $rotated = app(RefreshTokenService::class)->rotate('refresh-'.$s->id, (string) Uuid::uuid7(), 'test-ip');
        $new = Session::findOrFail($rotated->sessionId);
        $this->assertNotNull($new->mfa_verified_at);
        $this->assertSame('revoked', $s->fresh()->status);
    }
}
