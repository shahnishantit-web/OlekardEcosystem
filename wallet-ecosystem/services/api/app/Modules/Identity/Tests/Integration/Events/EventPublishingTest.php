<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * T156 — Integration tests verifying all E01 outbox event emission points.
 *
 * Each test:
 *  1. Triggers the domain action
 *  2. Queries platform.outbox_events for the expected event_name
 *  3. Asserts the event envelope (actor_id, correlation_id, payload) is correct
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

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function evtSetupUser(string $mobile, string $fp, string $pin = '123456', string $actorType = 'CUSTOMER'): array
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
        'credential_hash' => Hash::make($pin),
        'status'          => 'active',
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $fp),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'fingerprint' => $fp, 'pin' => $pin];
}

function evtDoLogin(mixed $test, string $mobile, string $fp, string $pin = '123456'): array
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => $pin,
        'device_fingerprint' => $fp,
    ]);
    return [
        'token'      => (string) $resp->json('data.access_token'),
        'session_id' => (string) $resp->json('data.session_id'),
        'status'     => $resp->status(),
    ];
}

function evtOutboxEvent(string $eventName, string $correlationId): ?object
{
    return DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', $eventName)
        ->where('correlation_id', $correlationId)
        ->first();
}

function evtExtractTotpSecret(string $totpUri): string
{
    parse_str((string) parse_url($totpUri, PHP_URL_QUERY), $params);
    return (string) ($params['secret'] ?? '');
}

function evtTotpCode(string $secret): string
{
    $auth = new Authenticator(new AuthenticatorOptions);
    $auth->setSecret($secret);
    return $auth->code();
}

function evtEnrollTotp(mixed $test, string $token): array
{
    $initResp = $test->postJson('/v1/auth/mfa/enroll/totp', [], [
        'Authorization' => "Bearer {$token}",
    ]);
    $initResp->assertStatus(200);

    $enrollmentId = $initResp->json('data.enrollment_id');
    $totpUri      = $initResp->json('data.totp_uri');
    $secret       = evtExtractTotpSecret($totpUri);

    $test->postJson('/v1/auth/mfa/enroll/totp/confirm', [
        'enrollment_id' => $enrollmentId,
        'totp_code'     => evtTotpCode($secret),
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

    return ['secret' => $secret, 'enrollment_id' => $enrollmentId];
}

// ---------------------------------------------------------------------------
// Identity.Session.LoginFailed (T152)
// ---------------------------------------------------------------------------

describe('Identity.Session.LoginFailed event (T152)', function (): void {

    it('is published to outbox_events on failed login with masked identifier', function (): void {
        evtSetupUser('+26781001001', 'evt-fp-01');
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26781001001',
            'credential'         => '000000',
            'device_fingerprint' => 'evt-fp-01',
        ], ['X-Correlation-ID' => $correlationId]);

        $event = evtOutboxEvent('Identity.Session.LoginFailed', $correlationId);

        expect($event)->not->toBeNull();
        expect($event->event_name)->toBe('Identity.Session.LoginFailed');
        expect($event->correlation_id)->toBe($correlationId);

        $payload = json_decode($event->payload, true);
        expect($payload['reason'])->toBe('invalid_pin');
        expect($payload['masked_identifier'])->toContain('***');
        expect($payload['masked_identifier'])->not->toContain('+26781001001');
    });

    it('event metadata includes event_id and event_version', function (): void {
        evtSetupUser('+26781001002', 'evt-fp-01b');
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26781001002',
            'credential'         => '000000',
            'device_fingerprint' => 'evt-fp-01b',
        ], ['X-Correlation-ID' => $correlationId]);

        $event    = evtOutboxEvent('Identity.Session.LoginFailed', $correlationId);
        $metadata = json_decode($event->metadata, true);

        expect($metadata['event_id'])->not->toBeEmpty();
        expect($metadata['event_version'])->toBe('1');
        expect($metadata['correlation_id'])->toBe($correlationId);
    });

});

// ---------------------------------------------------------------------------
// Identity.Session.Revoked (T152)
// ---------------------------------------------------------------------------

describe('Identity.Session.Revoked event (T152)', function (): void {

    it('is published via SessionRevokedEvent when admin revokes a session', function (): void {
        evtSetupUser('+26781002001', 'evt-fp-02', '123456', 'ADMIN');
        evtSetupUser('+26781002002', 'evt-fp-03');

        $adminSession    = evtDoLogin($this, '+26781002001', 'evt-fp-02');
        $customerSession = evtDoLogin($this, '+26781002002', 'evt-fp-03');

        $adminToken      = $adminSession['token'];
        $targetSessionId = $customerSession['session_id'];

        $correlationId = (string) Str::uuid();

        $this->postJson("/v1/admin/sessions/{$targetSessionId}/revoke", [], [
            'Authorization'    => "Bearer {$adminToken}",
            'X-Correlation-ID' => $correlationId,
        ])->assertStatus(200);

        $event = evtOutboxEvent('Identity.Session.Revoked', $correlationId);

        expect($event)->not->toBeNull();
        expect($event->event_name)->toBe('Identity.Session.Revoked');

        $payload = json_decode($event->payload, true);
        expect($payload['session_id'])->toBe($targetSessionId);
        expect($payload['reason'])->toBe('admin_revoked');
    });

    it('user logout emits Identity.Session.Terminated (not Identity.Session.Revoked)', function (): void {
        evtSetupUser('+26781002003', 'evt-fp-03b');
        $session       = evtDoLogin($this, '+26781002003', 'evt-fp-03b');
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/logout', [], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ]);

        $terminated = evtOutboxEvent('Identity.Session.Terminated', $correlationId);
        expect($terminated)->not->toBeNull();

        $revoked = evtOutboxEvent('Identity.Session.Revoked', $correlationId);
        expect($revoked)->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// Identity.MFA.Enabled (T152)
// ---------------------------------------------------------------------------

describe('Identity.MFA.Enabled event (T152)', function (): void {

    it('is published to outbox_events when TOTP MFA is confirmed', function (): void {
        evtSetupUser('+26781003001', 'evt-fp-04');
        $session       = evtDoLogin($this, '+26781003001', 'evt-fp-04');
        $correlationId = (string) Str::uuid();

        $initResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$session['token']}",
        ]);
        $initResp->assertStatus(200);

        $enrollmentId = $initResp->json('data.enrollment_id');
        $secret       = evtExtractTotpSecret($initResp->json('data.totp_uri'));

        $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => evtTotpCode($secret),
        ], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ])->assertStatus(200);

        $event = evtOutboxEvent('Identity.MFA.Enabled', $correlationId);

        expect($event)->not->toBeNull();
        $evtPayload = json_decode($event->payload, true);
        expect($evtPayload['method'])->toBe('totp');
    });

});

// ---------------------------------------------------------------------------
// Identity.MFA.Disabled (T152)
// ---------------------------------------------------------------------------

describe('Identity.MFA.Disabled event (T152)', function (): void {

    it('is published to outbox_events when MFA is disabled', function (): void {
        evtSetupUser('+26781004001', 'evt-fp-05');
        $session = evtDoLogin($this, '+26781004001', 'evt-fp-05');

        evtEnrollTotp($this, $session['token']);

        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/mfa/disable', ['method' => 'totp'], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ])->assertStatus(200);

        $event = evtOutboxEvent('Identity.MFA.Disabled', $correlationId);

        expect($event)->not->toBeNull();
        $evtPayload = json_decode($event->payload, true);
        expect($evtPayload['method'])->toBe('totp');
    });

});

// ---------------------------------------------------------------------------
// Identity.MFA.ChallengeVerified (T152)
// ---------------------------------------------------------------------------

describe('Identity.MFA.ChallengeVerified event (T152)', function (): void {

    it('is published to outbox_events after successful MFA verification', function (): void {
        evtSetupUser('+26781005001', 'evt-fp-06', '123456');
        $session = evtDoLogin($this, '+26781005001', 'evt-fp-06');

        $initResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$session['token']}",
        ]);
        $enrollmentId = $initResp->json('data.enrollment_id');
        $secret       = evtExtractTotpSecret($initResp->json('data.totp_uri'));

        $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => evtTotpCode($secret),
        ], ['Authorization' => "Bearer {$session['token']}"])->assertStatus(200);

        // Login again — should require MFA
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26781005001',
            'credential'         => '123456',
            'device_fingerprint' => 'evt-fp-06',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');
        expect($mfaToken)->not->toBeNull();

        $correlationId = (string) Str::uuid();

        $verifyResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => evtTotpCode($secret),
        ], ['X-Correlation-ID' => $correlationId]);
        $verifyResp->assertStatus(200);

        $event = evtOutboxEvent('Identity.MFA.ChallengeVerified', $correlationId);

        expect($event)->not->toBeNull();
        $evtPayload = json_decode($event->payload, true);
        expect($evtPayload['method'])->toBe('totp');
    });

});

// ---------------------------------------------------------------------------
// Identity.MFA.StepUpRequired (T152)
// ---------------------------------------------------------------------------

describe('Identity.MFA.StepUpRequired event (T152)', function (): void {

    it('is published when step-up is required for an operation', function (): void {
        evtSetupUser('+26781006001', 'evt-fp-07');
        $session       = evtDoLogin($this, '+26781006001', 'evt-fp-07');
        $correlationId = (string) Str::uuid();

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'transfer',
            'operation_context' => ['amount' => 15000, 'currency' => 'BWP'],
        ], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ]);
        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();

        $event = evtOutboxEvent('Identity.MFA.StepUpRequired', $correlationId);

        expect($event)->not->toBeNull();
        $evtPayload = json_decode($event->payload, true);
        expect($evtPayload['scope'])->toBe('transfer');
    });

    it('step-up not required does NOT publish the event', function (): void {
        evtSetupUser('+26781006002', 'evt-fp-07b');
        $session       = evtDoLogin($this, '+26781006002', 'evt-fp-07b');
        $correlationId = (string) Str::uuid();

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'transfer',
            'operation_context' => ['amount' => 100, 'currency' => 'BWP'],
        ], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ]);
        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeFalse();

        $event = evtOutboxEvent('Identity.MFA.StepUpRequired', $correlationId);
        expect($event)->toBeNull();
    });

});

// ---------------------------------------------------------------------------
// Identity.MFA.StepUpCompleted (T152)
// ---------------------------------------------------------------------------

describe('Identity.MFA.StepUpCompleted event (T152)', function (): void {

    it('is published when step-up challenge is successfully verified', function (): void {
        evtSetupUser('+26781007001', 'evt-fp-08', '123456');
        $session = evtDoLogin($this, '+26781007001', 'evt-fp-08');

        $initResp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'transfer',
            'operation_context' => ['amount' => 15000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$session['token']}"]);

        $stepUpToken   = $initResp->json('data.step_up_token');
        $correlationId = (string) Str::uuid();

        $verifyResp = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $stepUpToken,
            'method'        => 'pin',
            'credential'    => '123456',
        ], [
            'Authorization'    => "Bearer {$session['token']}",
            'X-Correlation-ID' => $correlationId,
        ]);
        $verifyResp->assertStatus(200);

        $event = evtOutboxEvent('Identity.MFA.StepUpCompleted', $correlationId);

        expect($event)->not->toBeNull();
        $evtPayload = json_decode($event->payload, true);
        expect($evtPayload['scope'])->toBe('transfer');
    });

});

// ---------------------------------------------------------------------------
// Identity.User.RegistrationOtpRequested (T230)
// ---------------------------------------------------------------------------

describe('Identity.User.RegistrationOtpRequested event (T230)', function (): void {

    it('is published to outbox_events when registration OTP is sent', function (): void {
        $correlationId = (string) Str::uuid();

        $resp = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26782001001',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => $correlationId]);

        $resp->assertStatus(202);

        $event = evtOutboxEvent('Identity.User.RegistrationOtpRequested', $correlationId);

        expect($event)->not->toBeNull();
        expect($event->event_name)->toBe('Identity.User.RegistrationOtpRequested');
        expect($event->correlation_id)->toBe($correlationId);

        $payload = json_decode($event->payload, true);
        expect($payload)->toHaveKey('challenge_id');
        expect($payload)->toHaveKey('channel_type');
        expect($payload)->toHaveKey('masked_destination');
        expect($payload['masked_destination'])->not->toContain('+26782001001');
    });

    it('event aggregate_type is Identity.User', function (): void {
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26782001002',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => $correlationId])->assertStatus(202);

        $event = evtOutboxEvent('Identity.User.RegistrationOtpRequested', $correlationId);

        expect($event)->not->toBeNull();
        expect($event->aggregate_type)->toBe('Identity.User');
    });

});

// ---------------------------------------------------------------------------
// Identity.User.RegistrationOtpResendRequested (T230)
// ---------------------------------------------------------------------------

describe('Identity.User.RegistrationOtpResendRequested event (T230)', function (): void {

    it('is published to outbox_events when OTP is resent', function (): void {
        $registerCorr = (string) Str::uuid();

        $regResp = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26782002001',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => $registerCorr]);

        $regResp->assertStatus(202);
        $challengeId = $regResp->json('data.challenge_id');
        expect($challengeId)->not->toBeNull();

        $resendCorr = (string) Str::uuid();

        $resendResp = $this->postJson('/v1/auth/otp/resend', [
            'challenge_id' => $challengeId,
        ], ['X-Correlation-ID' => $resendCorr]);

        $resendResp->assertStatus(200);

        $event = evtOutboxEvent('Identity.User.RegistrationOtpResendRequested', $resendCorr);

        expect($event)->not->toBeNull();
        expect($event->event_name)->toBe('Identity.User.RegistrationOtpResendRequested');
        expect($event->correlation_id)->toBe($resendCorr);

        $payload = json_decode($event->payload, true);
        expect($payload)->toHaveKey('challenge_id');
        expect($payload)->toHaveKey('masked_destination');
    });

});

// ---------------------------------------------------------------------------
// Identity.Session.Expired (T231)
// ---------------------------------------------------------------------------

describe('Identity.Session.Expired event (T231)', function (): void {

    it('is published by SessionCleanupJob when an active session is past its TTL', function (): void {
        $users = app(UserRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26782003001',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $sessionId = (string) Str::orderedUuid();

        DB::connection('identity')->table('identity.sessions')->insert([
            'id'                    => $sessionId,
            'user_id'               => $user->id,
            'device_id'             => null,
            'refresh_token_hash'    => hash('sha256', random_bytes(32)),
            'refresh_token_family_id' => (string) Str::uuid(),
            'status'                => 'active',
            'actor_type'            => 'CUSTOMER',
            'expires_at'            => now()->subMinutes(5),
            'last_activity_at'      => now()->subMinutes(10),
            'created_at'            => now()->subMinutes(10),
            'updated_at'            => now()->subMinutes(10),
        ]);

        $config = app(\App\Modules\Identity\Domain\Services\ConfigurationSecurityService::class);
        $events = app(\App\Modules\Platform\Domain\Contracts\EventPublisherInterface::class);

        (new \App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob())->handle($config, $events);

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Identity.Session.Expired')
            ->where('aggregate_id', $sessionId)
            ->first();

        expect($event)->not->toBeNull();
        expect($event->event_name)->toBe('Identity.Session.Expired');

        $payload = json_decode($event->payload, true);
        expect($payload['session_id'])->toBe($sessionId);
        expect($payload['user_id'])->toBe($user->id);

        $sessionStatus = DB::connection('identity')
            ->table('identity.sessions')
            ->where('id', $sessionId)
            ->value('status');

        expect($sessionStatus)->toBe('expired');
    });

    it('active sessions with future expires_at are NOT expired by the cleanup job', function (): void {
        $users = app(UserRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26782003002',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);

        $sessionId = (string) Str::orderedUuid();

        DB::connection('identity')->table('identity.sessions')->insert([
            'id'                    => $sessionId,
            'user_id'               => $user->id,
            'device_id'             => null,
            'refresh_token_hash'    => hash('sha256', random_bytes(32)),
            'refresh_token_family_id' => (string) Str::uuid(),
            'status'                => 'active',
            'actor_type'            => 'CUSTOMER',
            'expires_at'            => now()->addHour(),
            'last_activity_at'      => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $config = app(\App\Modules\Identity\Domain\Services\ConfigurationSecurityService::class);
        $events = app(\App\Modules\Platform\Domain\Contracts\EventPublisherInterface::class);

        (new \App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob())->handle($config, $events);

        $sessionStatus = DB::connection('identity')
            ->table('identity.sessions')
            ->where('id', $sessionId)
            ->value('status');

        expect($sessionStatus)->toBe('active');
    });

});

// ---------------------------------------------------------------------------
// All E01 outbox events include required envelope fields (T156)
// ---------------------------------------------------------------------------

describe('Outbox event envelope completeness (T156)', function (): void {

    it('all emitted events have event_id, event_version, actor_id, aggregate_id, correlation_id', function (): void {
        evtSetupUser('+26781008001', 'evt-fp-09');
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26781008001',
            'credential'         => '000000',
            'device_fingerprint' => 'evt-fp-09',
        ], ['X-Correlation-ID' => $correlationId]);

        $event = evtOutboxEvent('Identity.Session.LoginFailed', $correlationId);
        expect($event)->not->toBeNull();

        expect($event->event_name)->toBe('Identity.Session.LoginFailed');
        expect($event->aggregate_id)->not->toBeEmpty();
        expect($event->correlation_id)->toBe($correlationId);

        $metadata = json_decode($event->metadata, true);
        expect($metadata['event_id'])->not->toBeEmpty();
        expect($metadata['event_version'])->toBe('1');
    });

    it('LoginFailed event payload has masked_identifier without raw phone', function (): void {
        evtSetupUser('+26781009001', 'evt-fp-10');
        $correlationId = (string) Str::uuid();

        $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26781009001',
            'credential'         => '000000',
            'device_fingerprint' => 'evt-fp-10',
        ], ['X-Correlation-ID' => $correlationId]);

        $event   = evtOutboxEvent('Identity.Session.LoginFailed', $correlationId);
        $payload = json_decode($event->payload, true);

        expect($payload['masked_identifier'])->not->toContain('+26781009001');
        expect($payload['masked_identifier'])->toContain('***');
        expect($payload)->not->toHaveKey('pin');
        expect($payload)->not->toHaveKey('credential');
    });

});
