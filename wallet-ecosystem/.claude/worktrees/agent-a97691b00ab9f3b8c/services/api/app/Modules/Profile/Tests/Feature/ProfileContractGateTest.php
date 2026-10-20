<?php

declare(strict_types=1);

use App\Modules\Profile\Infrastructure\Http\Controllers\AccountStatusController;
use App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController;
use App\Modules\Profile\Infrastructure\Http\Controllers\ClosureController;
use App\Modules\Profile\Infrastructure\Http\Controllers\ContactChangeController;
use App\Modules\Profile\Infrastructure\Http\Controllers\DataExportController;
use App\Modules\Profile\Infrastructure\Http\Controllers\ProfileController;
use App\Modules\Profile\Infrastructure\Http\Controllers\ProfilePhotoController;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

// ---------------------------------------------------------------------------
// T176 — E02 Profile API Contract Gate
// ---------------------------------------------------------------------------

describe('E02 Profile API Contract Gate — all 19 routes registered (T176)', function (): void {

    it('all 19 E02 endpoint routes exist in the route collection', function (): void {
        $routes = app(Router::class)->getRoutes();

        $contracts = [
            ['GET',    '/v1/users/profile'],
            ['PUT',    '/v1/users/profile/personal'],
            ['POST',   '/v1/users/profile/photo'],
            ['POST',   '/v1/users/profile/email/change'],
            ['POST',   '/v1/users/profile/email/verify'],
            ['POST',   '/v1/users/profile/mobile/change'],
            ['POST',   '/v1/users/profile/mobile/verify'],
            ['GET',    '/v1/users/profile/status'],
            ['POST',   '/v1/users/profile/closure/request'],
            ['DELETE', '/v1/users/profile/closure/request'],
            ['POST',   '/v1/users/profile/data-export'],
            // parameterised routes: supply a concrete segment value to force matching
            ['GET',    '/v1/users/profile/data-export/test-export-id'],
            ['GET',    '/v1/admin/users/test-user-id/profile'],
            ['PUT',    '/v1/admin/users/test-user-id/profile/personal'],
            ['GET',    '/v1/admin/users/test-user-id/profile/status'],
            ['POST',   '/v1/admin/users/test-user-id/suspend'],
            ['POST',   '/v1/admin/users/test-user-id/reactivate'],
            ['POST',   '/v1/admin/users/test-user-id/cancel-closure'],
            ['POST',   '/v1/admin/users/test-user-id/close'],
        ];

        foreach ($contracts as [$method, $uri]) {
            $matched = $routes->match(
                \Illuminate\Http\Request::create($uri, $method)
            );
            expect($matched)->not->toBeNull("Route $method $uri must be registered in the router");
        }
    });

});

describe('E02 Profile API Contract Gate — controller classes and methods exist (T176)', function (): void {

    it('every E02 controller class exists', function (): void {
        $classes = [
            ProfileController::class,
            ProfilePhotoController::class,
            ContactChangeController::class,
            AccountStatusController::class,
            ClosureController::class,
            DataExportController::class,
            AdminProfileController::class,
        ];

        foreach ($classes as $class) {
            expect(class_exists($class))->toBeTrue("Controller class $class must exist");
        }
    });

    it('every E02 controller action method exists via reflection', function (): void {
        $bindings = [
            [ProfileController::class,       'show'],
            [ProfileController::class,       'updatePersonal'],
            [ProfilePhotoController::class,  'store'],
            [ContactChangeController::class, 'initiateEmail'],
            [ContactChangeController::class, 'verifyEmail'],
            [ContactChangeController::class, 'initiateMobile'],
            [ContactChangeController::class, 'verifyMobile'],
            [AccountStatusController::class, 'show'],
            [ClosureController::class,       'request'],
            [ClosureController::class,       'cancel'],
            [DataExportController::class,    'request'],
            [DataExportController::class,    'status'],
            [AdminProfileController::class,  'show'],
            [AdminProfileController::class,  'updatePersonal'],
            [AdminProfileController::class,  'status'],
            [AdminProfileController::class,  'suspend'],
            [AdminProfileController::class,  'reactivate'],
            [AdminProfileController::class,  'cancelClosure'],
            [AdminProfileController::class,  'close'],
        ];

        foreach ($bindings as [$class, $method]) {
            expect(method_exists($class, $method))->toBeTrue(
                "$class::$method must exist — check route registration matches controller signature"
            );
        }
    });

});

describe('E02 Profile API Contract Gate — Idempotency-Key enforcement on state-changing routes (T176)', function (): void {

    it('PUT /v1/users/profile/personal without Idempotency-Key must not return 200', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson(
            '/v1/users/profile/personal',
            ['first_name' => 'GateTest'],
            ['X-Test-User-Id' => $userId],
            // Intentionally omit Idempotency-Key
        );

        expect($response->status())->not->toBe(200,
            'PUT /v1/users/profile/personal must reject requests missing Idempotency-Key (not return 200)'
        );
    });

    it('POST /v1/users/profile/closure/request without Idempotency-Key must not return 200', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            ['X-Test-User-Id' => $userId],
            // Intentionally omit Idempotency-Key
        );

        expect($response->status())->not->toBe(200,
            'POST /v1/users/profile/closure/request must reject requests missing Idempotency-Key'
        );
    });

    it('POST /v1/users/profile/email/change without Idempotency-Key must not return 200', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'gate-test@example.com'],
            ['X-Test-User-Id' => $userId],
            // Intentionally omit Idempotency-Key
        );

        expect($response->status())->not->toBe(200,
            'POST /v1/users/profile/email/change must reject requests missing Idempotency-Key'
        );
    });

});

describe('E02 Profile API Contract Gate — Platform Foundation ApiResponse envelope shape (T176)', function (): void {

    it('GET /v1/users/profile returns the Platform Foundation ApiResponse envelope with success, data, and error keys', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        $body = $response->json();

        expect($body)->toHaveKey('success', 'ApiResponse envelope must contain success');
        expect($body)->toHaveKey('data',    'ApiResponse envelope must contain data');
        expect($body)->toHaveKey('error',   'ApiResponse envelope must contain error');
        expect($body['success'])->toBeTrue();
        expect($body['error'])->toBeNull();
        expect($body['data'])->toBeArray();
        expect($body['data'])->toHaveKey('user_id');
        expect($body['data'])->toHaveKey('account_status');
        // profile_photo_key must never appear in any response
        expect($body['data'])->not->toHaveKey('profile_photo_key',
            'profile_photo_key is a security-sensitive internal field and must never appear in API responses'
        );
    });

});
