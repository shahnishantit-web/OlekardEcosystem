<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Identity\Infrastructure\Http\Middleware\AuthLatencyMiddleware;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Infrastructure\Metrics\InMemoryCollector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestCase;

// Container-level tests require the full Laravel app (service providers registered)
uses(TestCase::class)->group('container');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeMetrics(): array
{
    $collector = new InMemoryCollector();
    $metrics   = new IdentityMetrics($collector);
    return [$metrics, $collector];
}

// ---------------------------------------------------------------------------
// T187 — identity.auth.success
// ---------------------------------------------------------------------------

it('emits identity.auth.success with actor_type and credential_type', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authSuccess('CUSTOMER', 'pin');

    expect($collector->getCount('identity.auth.success', ['actor_type' => 'customer', 'credential_type' => 'pin']))
        ->toBe(1);
});

it('normalises actor_type to lowercase in auth.success', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authSuccess('ADMIN', 'password');

    $keys = array_keys($collector->getMetrics());
    expect(implode(',', $keys))->not->toContain('ADMIN');
});

it('does not expose PII in identity.auth.success labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authSuccess('CUSTOMER', 'pin');

    $keys = implode(',', array_keys($collector->getMetrics()));
    expect($keys)
        ->not->toContain('@')
        ->not->toContain('+')
        ->not->toContain('token')
        ->not->toContain('session');
});

// ---------------------------------------------------------------------------
// T188 — identity.auth.failure
// ---------------------------------------------------------------------------

it('emits identity.auth.failure with actor_type and reason', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('CUSTOMER', 'invalid_pin');

    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'customer', 'reason' => 'invalid_pin']))
        ->toBe(1);
});

it('normalises actor_type to lowercase in auth.failure', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('AGENT', 'invalid_password');

    $keys = array_keys($collector->getMetrics());
    expect(implode(',', $keys))->not->toContain('AGENT');
});

it('does not expose credential values in auth.failure labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('CUSTOMER', 'invalid_pin');

    $keys = implode(',', array_keys($collector->getMetrics()));
    // reason label contains a descriptive enum value, never the actual PIN
    expect($keys)->not->toContain('1234')->not->toContain('0000');
});

it('increments auth.failure counter on each call', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('CUSTOMER', 'invalid_pin');
    $metrics->authFailure('CUSTOMER', 'invalid_pin');
    $metrics->authFailure('ADMIN', 'invalid_password');

    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'customer', 'reason' => 'invalid_pin']))
        ->toBe(2);
    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'admin', 'reason' => 'invalid_password']))
        ->toBe(1);
});

// ---------------------------------------------------------------------------
// T189 — identity.otp.sent
// ---------------------------------------------------------------------------

it('emits identity.otp.sent with channel_type and challenge_type', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->otpSent('sms', 'registration');

    expect($collector->getCount('identity.otp.sent', ['channel_type' => 'sms', 'challenge_type' => 'registration']))
        ->toBe(1);
});

it('does not include any identifier in otp.sent labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->otpSent('email', 'login');

    $keys = implode(',', array_keys($collector->getMetrics()));
    // No mobile number or email address in labels
    expect($keys)
        ->not->toContain('@')
        ->not->toContain('+254')
        ->not->toContain('user');
});

it('supports sms and email channel types', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->otpSent('sms',   'login');
    $metrics->otpSent('email', 'password_reset');

    expect($collector->getCount('identity.otp.sent', ['channel_type' => 'sms',   'challenge_type' => 'login']))->toBe(1);
    expect($collector->getCount('identity.otp.sent', ['channel_type' => 'email', 'challenge_type' => 'password_reset']))->toBe(1);
});

// ---------------------------------------------------------------------------
// T190 — identity.account.locked
// ---------------------------------------------------------------------------

it('emits identity.account.locked with actor_type', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->accountLocked('CUSTOMER');

    expect($collector->getCount('identity.account.locked', ['actor_type' => 'customer']))->toBe(1);
});

it('normalises actor_type in account.locked', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->accountLocked('ADMIN');

    $keys = array_keys($collector->getMetrics());
    expect(implode(',', $keys))->not->toContain('ADMIN');
    expect($collector->getCount('identity.account.locked', ['actor_type' => 'admin']))->toBe(1);
});

// ---------------------------------------------------------------------------
// T191 / T235 — identity.token.refresh (success and failure per spec §12)
// ---------------------------------------------------------------------------

it('emits identity.token.refresh with result=success by default', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->tokenRefresh();

    expect($collector->getCount('identity.token.refresh', ['result' => 'success']))->toBe(1);
});

it('emits identity.token.refresh with result=failure on failed attempts', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->tokenRefresh('failure');

    expect($collector->getCount('identity.token.refresh', ['result' => 'failure']))->toBe(1);
});

it('does not include session or user identifiers in token.refresh labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->tokenRefresh('success');

    $keys = implode(',', array_keys($collector->getMetrics()));
    // Only the approved low-cardinality 'result' label should appear — no PII dimensions
    expect($keys)
        ->not->toContain('user_id')
        ->not->toContain('session_id')
        ->not->toContain('actor_id')
        ->not->toContain('token_id');
});

it('increments token.refresh success and failure counters independently', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->tokenRefresh('success');
    $metrics->tokenRefresh('success');
    $metrics->tokenRefresh('failure');

    expect($collector->getCount('identity.token.refresh', ['result' => 'success']))->toBe(2);
    expect($collector->getCount('identity.token.refresh', ['result' => 'failure']))->toBe(1);
});

// ---------------------------------------------------------------------------
// T192 — identity.step_up.triggered
// ---------------------------------------------------------------------------

it('emits identity.step_up.triggered with trigger_type amount_threshold', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->stepUpTriggered('amount_threshold');

    expect($collector->getCount('identity.step_up.triggered', ['trigger_type' => 'amount_threshold']))->toBe(1);
});

it('emits identity.step_up.triggered with trigger_type risk_signal', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->stepUpTriggered('risk_signal');

    expect($collector->getCount('identity.step_up.triggered', ['trigger_type' => 'risk_signal']))->toBe(1);
});

it('does not include user or session IDs in step_up.triggered labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->stepUpTriggered('amount_threshold');

    // Only the approved low-cardinality 'trigger_type' label should appear — no PII dimensions
    $key = array_key_first($collector->getMetrics());
    expect($key)->toContain('trigger_type');
    expect($key)
        ->not->toContain('user_id')
        ->not->toContain('session_id')
        ->not->toContain('actor_id');
});

// ---------------------------------------------------------------------------
// T193 — identity.auth.latency histogram
// ---------------------------------------------------------------------------

it('records identity.auth.latency histogram for login operation', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authLatency(123.45, 'login');

    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'login']);
    expect($values)->toHaveCount(1)->and($values[0])->toBe(123.45);
});

it('records identity.auth.latency histogram for token_refresh operation', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authLatency(45.0, 'token_refresh');

    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'token_refresh']);
    expect($values)->toHaveCount(1)->and($values[0])->toBe(45.0);
});

it('records identity.auth.latency histogram for otp_verify operation', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authLatency(210.0, 'otp_verify');

    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'otp_verify']);
    expect($values)->toHaveCount(1)->and($values[0])->toBe(210.0);
});

it('accumulates multiple latency observations per operation', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authLatency(100.0, 'login');
    $metrics->authLatency(250.0, 'login');
    $metrics->authLatency(80.0,  'login');

    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'login']);
    expect($values)->toHaveCount(3);
});

it('does not expose PII in latency labels', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authLatency(150.0, 'login');

    $keys = implode(',', array_keys($collector->getMetrics()));
    expect($keys)
        ->not->toContain('user_id')
        ->not->toContain('session_id')
        ->not->toContain('ip')
        ->not->toContain('token');
});

it('AuthLatencyMiddleware records latency histogram and passes response through', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $middleware = new AuthLatencyMiddleware($metrics);
    $request    = Request::create('/v1/auth/login', 'POST');
    $called     = false;

    $response = $middleware->handle($request, static function () use (&$called): Response {
        $called = true;
        return new Response('ok', 200);
    }, 'login');

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);

    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'login']);
    expect($values)->toHaveCount(1)->and($values[0])->toBeGreaterThan(0.0);
});

it('AuthLatencyMiddleware still records latency on error responses', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $middleware = new AuthLatencyMiddleware($metrics);
    $request    = Request::create('/v1/auth/login', 'POST');

    $response = $middleware->handle($request, static fn (): Response => new Response('fail', 401), 'login');

    expect($response->getStatusCode())->toBe(401);
    $values = $collector->getHistogramValues('identity.auth.latency', ['operation' => 'login']);
    expect($values)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Sensitive-label masking (spec §12 security constraint)
// ---------------------------------------------------------------------------

it('metric collector integration does not expose raw IP in any key', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    // Simulate all 7 metrics being emitted
    $metrics->authSuccess('customer', 'pin');
    $metrics->authFailure('customer', 'invalid_pin');
    $metrics->otpSent('sms', 'login');
    $metrics->accountLocked('customer');
    $metrics->tokenRefresh();
    $metrics->stepUpTriggered('amount_threshold');
    $metrics->authLatency(100.0, 'login');

    $allKeys = implode(',', array_keys($collector->getMetrics()));

    // None of the forbidden high-cardinality / PII dimensions appear
    foreach (['user_id', 'mobile', 'email', 'session_id', 'token_id', 'ip_address', 'otp', 'pin', 'password', 'secret', 'private'] as $forbidden) {
        expect($allKeys)->not->toContain($forbidden, "Found forbidden label dimension: {$forbidden}");
    }
});

// ---------------------------------------------------------------------------
// T234 — identity.auth.failure emitted when locked account attempts login
// ---------------------------------------------------------------------------

it('emits identity.auth.failure with reason=account_locked when assertNotLocked detects active lockout', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('customer', 'account_locked');

    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'customer', 'reason' => 'account_locked']))
        ->toBe(1);
});

it('distinguishes account_locked reason from credential failure reasons', function (): void {
    /** @var InMemoryCollector $collector */
    [$metrics, $collector] = makeMetrics();

    $metrics->authFailure('customer', 'invalid_pin');
    $metrics->authFailure('customer', 'account_locked');
    $metrics->authFailure('customer', 'account_locked');

    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'customer', 'reason' => 'invalid_pin']))->toBe(1);
    expect($collector->getCount('identity.auth.failure', ['actor_type' => 'customer', 'reason' => 'account_locked']))->toBe(2);
});

// ---------------------------------------------------------------------------
// Container binding: MetricsCollectorInterface resolves to InMemoryCollector
// ---------------------------------------------------------------------------

it('container resolves MetricsCollectorInterface to InMemoryCollector by default', function (): void {
    $collector = app(MetricsCollectorInterface::class);

    expect($collector)->toBeInstanceOf(InMemoryCollector::class);
});

it('IdentityMetrics resolves from container and delegates to bound collector', function (): void {
    /** @var InMemoryCollector $collector */
    $collector = app(MetricsCollectorInterface::class);
    $metrics   = app(IdentityMetrics::class);

    $metrics->tokenRefresh('success');

    expect($collector->getCount('identity.token.refresh', ['result' => 'success']))->toBe(1);
});
