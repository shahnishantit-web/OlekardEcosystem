<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Support\Facades\DB;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 13 Step-Up Authentication Feature Tests — T130, T131, T133
 *
 * Covers:
 * - Amount above configured threshold triggers step-up (AC-013; FR-E01-046)
 * - Amount below threshold does NOT trigger step-up
 * - Risk signal independently triggers step-up regardless of amount
 * - Step-up token is correctly scoped; different scope → correct payload
 * - Multi-currency: FX unavailable → fail-safe (step-up required) (FR-E01-047; BR-024)
 * - Configuration test: threshold read from DB at runtime; literal 10000 absent from domain services (AC-013; AC-017)
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

function supCreateUser(string $mobile, string $fp = 'sup-device', string $actorType = 'CUSTOMER'): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => $actorType,
    ]);
    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('654321'),
        'status'          => 'active',
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $fp),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'fingerprint' => $fp];
}

function supLogin(mixed $test, string $mobile, string $fp = 'sup-device'): array
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '654321',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);
    return [
        'access_token' => (string) $resp->json('data.access_token'),
        'session_id'   => (string) $resp->json('data.session_id'),
    ];
}

function supEnrollTotp(mixed $test, string $mobile, string $fp, string $token): string
{
    $enroll   = $test->postJson('/v1/auth/mfa/enroll/totp', [], ['Authorization' => "Bearer {$token}"]);
    $secret   = (function (string $uri): string {
        parse_str(parse_url($uri, PHP_URL_QUERY), $p);
        return (string) ($p['secret'] ?? '');
    })($enroll->json('data.totp_uri'));

    $auth = new Authenticator(new AuthenticatorOptions);
    $auth->setSecret($secret);
    $test->postJson('/v1/auth/mfa/enroll/totp/confirm', [
        'enrollment_id' => $enroll->json('data.enrollment_id'),
        'totp_code'     => $auth->code(),
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

    return $secret;
}

// ============================================================================
// T130: Threshold evaluation (AC-013; FR-E01-046)
// ============================================================================

describe('Amount threshold evaluation (T130)', function (): void {

    it('amount above configured threshold (10000 BWP) triggers step-up', function (): void {
        supCreateUser('+26773001001');
        $auth = supLogin($this, '+26773001001');

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 15000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();
        expect($resp->json('data.step_up_token'))->not->toBeNull();
        expect($resp->json('data.methods'))->toBeArray();
        expect($resp->json('data.expires_at'))->not->toBeNull();
    });

    it('amount equal to threshold does NOT trigger step-up (boundary: amount must exceed threshold)', function (): void {
        supCreateUser('+26773001002');
        $auth = supLogin($this, '+26773001002');

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:normal',
            'operation_context' => ['amount' => 10000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeFalse();
        expect($resp->json('data'))->not->toHaveKey('step_up_token');
    });

    it('amount below threshold does NOT trigger step-up', function (): void {
        supCreateUser('+26773001003');
        $auth = supLogin($this, '+26773001003');

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:small',
            'operation_context' => ['amount' => 500, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeFalse();
        expect($resp->json('data'))->not->toHaveKey('step_up_token');
    });

    it('risk signal independently triggers step-up even when amount is below threshold', function (): void {
        supCreateUser('+26773001004');
        $auth = supLogin($this, '+26773001004');

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'risk:triggered',
            'operation_context' => [
                'amount'        => 100,
                'currency'      => 'BWP',
                'risk_required' => true,
            ],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();
        expect($resp->json('data.step_up_token'))->not->toBeNull();
    });

    it('full step-up flow: initiate → verify with PIN → step-up token returned with correct scope', function (): void {
        supCreateUser('+26773001005');
        $auth  = supLogin($this, '+26773001005');

        $init = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $init->assertStatus(200);
        expect($init->json('data.step_up_required'))->toBeTrue();
        expect($init->json('data.methods'))->toContain('pin');

        $stepUpToken = $init->json('data.step_up_token');

        $verify = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $stepUpToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $verify->assertStatus(200);
        expect($verify->json('success'))->toBeTrue();
        expect($verify->json('data.step_up_access_token'))->not->toBeNull();
        expect($verify->json('data.scope'))->toBe('payment:high-value');
        expect($verify->json('data.expires_in'))->toBeInt();
        expect($verify->json('data.expires_in'))->toBeGreaterThan(0);
    });

    it('step-up token is single-use: replay attempt returns 401', function (): void {
        supCreateUser('+26773001006');
        $auth = supLogin($this, '+26773001006');

        $init = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $stepUpToken = $init->json('data.step_up_token');

        // First verify: succeeds
        $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $stepUpToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"])->assertStatus(200);

        // Replay: fails
        $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $stepUpToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"])->assertStatus(401);
    });

    it('full step-up flow: initiate → verify with TOTP → step-up token returned', function (): void {
        supCreateUser('+26773001007');
        $auth   = supLogin($this, '+26773001007');
        $secret = supEnrollTotp($this, '+26773001007', 'sup-device', $auth['access_token']);

        $init = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'admin:privileged',
            'operation_context' => ['amount' => 50000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $init->assertStatus(200);
        expect($init->json('data.methods'))->toContain('totp');

        $totpCode = (function () use ($secret): string {
            $a = new Authenticator(new AuthenticatorOptions);
            $a->setSecret($secret);
            return $a->code();
        })();

        $verify = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $init->json('data.step_up_token'),
            'method'        => 'totp',
            'credential'    => $totpCode,
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $verify->assertStatus(200);
        expect($verify->json('data.step_up_access_token'))->not->toBeNull();
        expect($verify->json('data.scope'))->toBe('admin:privileged');
    });

});

// ============================================================================
// T131: Multi-currency — FX unavailable → fail-safe (FR-E01-047; BR-024)
// ============================================================================

describe('Multi-currency step-up (T131)', function (): void {

    it('amount in non-BWP currency with FX unavailable requires step-up (fail-safe, BR-024)', function (): void {
        supCreateUser('+26773002001');
        $auth = supLogin($this, '+26773002001');

        // USD amount that would be below threshold if converted 1:1, but FX is unavailable
        // FX unavailable → fail-safe → step-up required regardless of amount
        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:fx',
            'operation_context' => ['amount' => 100, 'currency' => 'USD'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        // FX unavailable → step-up required (BR-024 fail-safe)
        expect($resp->json('data.step_up_required'))->toBeTrue();
        expect($resp->json('data.step_up_token'))->not->toBeNull();
    });

    it('amount in same currency (BWP) is compared directly without FX', function (): void {
        supCreateUser('+26773002002');
        $auth = supLogin($this, '+26773002002');

        // 5000 BWP is below 10000 BWP threshold — no step-up needed
        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:bwp-below',
            'operation_context' => ['amount' => 5000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeFalse();
    });

});

// ============================================================================
// T133: Configuration — threshold read from DB at runtime (AC-013; AC-017)
// ============================================================================

describe('Configuration tests (T133)', function (): void {

    it('step-up threshold is read from ConfigurationSecurityService, not hard-coded', function (): void {
        $svc = app(ConfigurationSecurityService::class);
        expect($svc->getStepUpThreshold())->toBe(10000);
        expect($svc->getStepUpThresholdCurrency())->toBe('BWP');
    });

    it('threshold of 10000 BWP does not appear as a literal in StepUpService source', function (): void {
        $source = file_get_contents(
            app_path('Modules/Identity/Domain/Services/StepUpService.php')
        );
        // The literal integer 10000 MUST NOT appear in any domain conditional (AC-013; AC-017)
        expect($source)->not->toContain('10000');
    });

    it('changing auth.step_up_threshold in DB takes effect on next evaluation', function (): void {
        supCreateUser('+26773003001');
        $auth      = supLogin($this, '+26773003001');
        $configSvc = app(ConfigurationServiceInterface::class);

        // Lower threshold to 5000 for this test
        DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->update(['config_value' => '5000']);
        $configSvc->invalidate('auth.step_up_threshold');

        // 7000 > 5000 → step-up required with new threshold
        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:adjusted',
            'operation_context' => ['amount' => 7000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();

        // Restore threshold
        DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->update(['config_value' => '10000']);
        $configSvc->invalidate('auth.step_up_threshold');
    });

    it('step-up token TTL is read from ConfigurationSecurityService', function (): void {
        $svc = app(ConfigurationSecurityService::class);
        $ttl = $svc->getStepUpTokenTtl();
        expect($ttl)->toBeInt();
        expect($ttl)->toBeGreaterThan(0);
    });

    it('missing step_up_token, method or credential returns 422', function (): void {
        supCreateUser('+26773003002');
        $auth = supLogin($this, '+26773003002');

        $resp = $this->postJson('/v1/auth/step-up/verify', [], [
            'Authorization' => "Bearer {$auth['access_token']}",
        ]);
        $resp->assertStatus(422);
        expect($resp->json('error.code'))->toBe('IDENTITY_019');
    });

});
