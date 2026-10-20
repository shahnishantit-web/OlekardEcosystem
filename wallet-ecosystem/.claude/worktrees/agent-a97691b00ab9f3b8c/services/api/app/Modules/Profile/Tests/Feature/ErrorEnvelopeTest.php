<?php

declare(strict_types=1);

use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

// StubFinancialObligationService always returns hasObligations:true (ADR-E02-003),
// so closure requests are always blocked without any additional mocking.

// ---------------------------------------------------------------------------
// T260 — Controller-caught error responses must conform to the platform envelope
//
// Platform envelope contract (Handler::errorEnvelope):
//   { success: false, data: null, error: { code, message, details[] }, correlation_id, meta: null }
//
// This test exercises paths where the controller catches the exception itself
// (not the global Handler) and verifies the body structure matches the contract.
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('Controller error responses — platform envelope compliance (T260)', function (): void {

    it('PROFILE_NOT_FOUND from ProfileController::show() conforms to the platform envelope', function (): void {
        $userId = (string) Uuid::uuid7();

        // No profile seeded — ProfileController::show() catches ProfileNotFoundException
        $response = $this->getJson(
            '/v1/users/profile',
            ['X-Test-User-Id' => $userId, 'X-Correlation-ID' => 'corr-test-001'],
        );

        $response->assertStatus(404);

        // Core structure
        expect($response->json('success'))->toBeFalse();
        expect($response->json('data'))->toBeNull();
        expect($response->json('meta'))->toBeNull();

        // correlation_id at the TOP level — not inside error
        expect($response->json('correlation_id'))->toBe('corr-test-001');

        // error object: code, message, details[]
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
        expect($response->json('error.message'))->not->toBeEmpty();
        expect($response->json('error.details'))->toBeArray();

        // non-standard trace_id key MUST NOT appear anywhere in the body
        expect($response->json())->not->toHaveKey('trace_id');
        expect($response->json('error'))->not->toHaveKey('trace_id');
    });

    it('PROFILE_NOT_FOUND from AccountStatusController::show() conforms to the platform envelope', function (): void {
        $userId = (string) Uuid::uuid7();

        $response = $this->getJson(
            '/v1/users/profile/status',
            ['X-Test-User-Id' => $userId, 'X-Correlation-ID' => 'corr-test-002'],
        );

        $response->assertStatus(404);
        expect($response->json('success'))->toBeFalse();
        expect($response->json('data'))->toBeNull();
        expect($response->json('meta'))->toBeNull();
        expect($response->json('correlation_id'))->toBe('corr-test-002');
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
        expect($response->json('error.details'))->toBeArray();
        expect($response->json('error'))->not->toHaveKey('trace_id');
    });

    it('CLOSURE_BLOCKED_BY_OBLIGATIONS from ClosureController::request() conforms to the platform envelope', function (): void {
        $userId = (string) Uuid::uuid7();

        // Seed an active profile so the closure path is reached.
        // StubFinancialObligationService (always-blocked) triggers ClosureBlockedByObligationsException
        // without any mocking — it's the default binding in test environment (ADR-E02-003).
        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);

        $idempKey = (string) Uuid::uuid7();

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => $idempKey, 'X-Correlation-ID' => 'corr-test-003'],
        );

        $response->assertStatus(409);

        expect($response->json('success'))->toBeFalse();
        expect($response->json('data'))->toBeNull();
        expect($response->json('meta'))->toBeNull();
        expect($response->json('correlation_id'))->toBe('corr-test-003');
        expect($response->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
        expect($response->json('error.details'))->toBeArray()->not->toBeEmpty();

        // blocked_by must be inside details[], NOT as a flat error key
        expect($response->json('error'))->not->toHaveKey('blocked_by');
        expect($response->json('error'))->not->toHaveKey('trace_id');
        expect($response->json('error.details.0.blocked_by'))->not->toBeNull();
    });

    it('error body contains no internal stack trace or file-path keys', function (): void {
        $userId = (string) Uuid::uuid7();

        $response = $this->getJson(
            '/v1/users/profile',
            ['X-Test-User-Id' => $userId, 'X-Correlation-ID' => 'corr-test-004'],
        );

        $response->assertStatus(404);
        $body = $response->json();

        // Platform contract forbids leaking internals
        expect($body)->not->toHaveKey('exception');
        expect($body)->not->toHaveKey('trace');
        expect($body)->not->toHaveKey('file');
        expect($body)->not->toHaveKey('line');
        expect($body)->not->toHaveKey('trace_id');
    });

});
