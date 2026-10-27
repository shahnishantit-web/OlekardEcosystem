<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E01 Identity & Authentication Routes
|--------------------------------------------------------------------------
| All E01 endpoints are prefixed /v1/ and registered here.
| Routes are loaded by IdentityServiceProvider::boot().
|
| Middleware stacks (applied per group):
|   public:        cors, throttle:identity-public, correlation-id
|   bearer-auth:   + identity.auth
|   admin:         + identity.require-actor-type:admin
|   refresh-token: cors, throttle:identity-refresh, correlation-id
|
| Routes are added incrementally as controller phases complete (T052–T168).
*/

Route::prefix('v1')->group(static function (): void {

    // Reviewed organisation authority for P24. Registration evidence is opaque;
    // a distinct privileged reviewer must approve before membership resolves.
    Route::prefix('identity/organisations')
        ->middleware(['identity.auth', 'identity.require-actor-type:admin', 'idempotency:require-identity'])
        ->group(static function (): void {
            $controller = \App\Modules\Identity\Infrastructure\Http\Controllers\OrganisationRegistryController::class;
            Route::post('/', [$controller, 'requestOrganisation'])->middleware('identity.require-permission:organisation.registry.request');
            Route::post('{id}/review', [$controller, 'reviewOrganisation'])->middleware('identity.require-permission:organisation.registry.review');
            Route::post('{id}/suspend', [$controller, 'suspendOrganisation'])->middleware('identity.require-permission:organisation.registry.revoke');
            Route::post('{id}/memberships', [$controller, 'requestMembership'])->middleware('identity.require-permission:organisation.registry.request');
            Route::post('memberships/{id}/review', [$controller, 'reviewMembership'])->middleware('identity.require-permission:organisation.registry.review');
            Route::post('memberships/{id}/revoke', [$controller, 'revokeMembership'])->middleware('identity.require-permission:organisation.registry.revoke');
        });

    // -------------------------------------------------------------------------
    // Customer app — phone-first auth (registration + two-step PIN login)
    // -------------------------------------------------------------------------
    Route::prefix('customer/auth')->middleware('throttle:identity-public')->group(static function (): void {
        Route::post('register/initiate', [\App\Modules\Identity\Infrastructure\Http\Controllers\CustomerAuthController::class, 'initiateRegistration']);
        Route::post('register/verify',   [\App\Modules\Identity\Infrastructure\Http\Controllers\CustomerAuthController::class, 'verifyRegistration']);
        Route::post('register/complete', [\App\Modules\Identity\Infrastructure\Http\Controllers\CustomerAuthController::class, 'completeRegistration']);
        Route::post('login',             [\App\Modules\Identity\Infrastructure\Http\Controllers\CustomerAuthController::class, 'initiateLogin']);
        Route::post('login/verify',      [\App\Modules\Identity\Infrastructure\Http\Controllers\CustomerAuthController::class, 'verifyLogin']);
    });

    // -------------------------------------------------------------------------
    // Agent app — single-step PIN login
    // -------------------------------------------------------------------------
    Route::prefix('agent/auth')->middleware('throttle:identity-public')->group(static function (): void {
        Route::post('login', [\App\Modules\Identity\Infrastructure\Http\Controllers\AgentAuthController::class, 'login']);
    });

    // -------------------------------------------------------------------------
    // Phase 5: Registration & OTP (T055)
    // -------------------------------------------------------------------------
    Route::prefix('auth')->middleware('throttle:identity-public')->group(static function (): void {
        Route::post('register',   [\App\Modules\Identity\Infrastructure\Http\Controllers\AuthController::class, 'register']);
        Route::post('otp/verify', [\App\Modules\Identity\Infrastructure\Http\Controllers\AuthController::class, 'verifyOtp'])
            ->middleware('identity.latency:otp_verify');
        Route::post('otp/resend', [\App\Modules\Identity\Infrastructure\Http\Controllers\AuthController::class, 'resendOtp']);
    });

    // -------------------------------------------------------------------------
    // Phase 6: Authentication / Login (T067)
    // -------------------------------------------------------------------------
    Route::post('auth/login', [\App\Modules\Identity\Infrastructure\Http\Controllers\AuthController::class, 'login'])
        ->middleware(['throttle:identity-public', 'identity.latency:login']);
    Route::middleware('throttle:identity-public')->group(static function (): void {
        Route::get('auth/login/biometric/challenge', [\App\Modules\Identity\Infrastructure\Http\Controllers\BiometricController::class, 'challenge']);
        Route::post('auth/login/biometric', [\App\Modules\Identity\Infrastructure\Http\Controllers\BiometricController::class, 'verify']);
    });

    // -------------------------------------------------------------------------
    // Phase 7: JWKS public endpoint (T076)
    // -------------------------------------------------------------------------
    Route::get('.well-known/jwks.json', [\App\Modules\Identity\Infrastructure\Http\Controllers\JwksController::class, 'index']);

    // -------------------------------------------------------------------------
    // Phase 8: Session & Refresh Token Lifecycle (T086)
    // -------------------------------------------------------------------------

    // Token refresh — no auth required (presents the refresh token itself for authentication)
    Route::post('auth/token/refresh', [\App\Modules\Identity\Infrastructure\Http\Controllers\TokenController::class, 'refresh'])
        ->middleware(['throttle:identity-refresh', 'identity.latency:token_refresh']);

    // -------------------------------------------------------------------------
    // Phase 9: Device Verification (T098)
    // -------------------------------------------------------------------------
    // No auth required — pre-session; user proves device ownership via OTP
    Route::post('auth/devices/verify', [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceController::class, 'verify'])
        ->middleware('throttle:identity-public');

    // Session management — requires valid Bearer JWT
    Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {
        Route::get('auth/sessions',              [\App\Modules\Identity\Infrastructure\Http\Controllers\SessionController::class, 'index']);
        Route::post('auth/logout',               [\App\Modules\Identity\Infrastructure\Http\Controllers\SessionController::class, 'logout']);
        Route::delete('auth/sessions',           [\App\Modules\Identity\Infrastructure\Http\Controllers\SessionController::class, 'logoutAll']);
        Route::delete('auth/sessions/{id}',      [\App\Modules\Identity\Infrastructure\Http\Controllers\SessionController::class, 'revoke']);

        // Device management
        Route::post('auth/devices/register',     [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceController::class, 'register']);
        Route::get('auth/devices',               [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceController::class, 'index']);
        Route::delete('auth/devices/{id}',       [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceController::class, 'revoke']);
    });

    // -------------------------------------------------------------------------
    // ADM-P1-001: Admin Password Recovery & Email Verification — public, throttled
    // -------------------------------------------------------------------------
    Route::prefix('admin/auth')->middleware('throttle:identity-public')->group(static function (): void {
        Route::post('forgot-password',       [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminPasswordRecoveryController::class,    'forgotPassword']);
        Route::post('reset-password',        [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminPasswordRecoveryController::class,    'resetPassword']);
        Route::post('verify-email',          [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminEmailVerificationController::class,   'verifyEmail']);
        Route::post('resend-verification',   [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminEmailVerificationController::class,   'resendVerification']);
    });

    // -------------------------------------------------------------------------
    // ADM-P1-003: Admin self-service credential management — requires admin auth
    // -------------------------------------------------------------------------
    Route::middleware(['throttle:identity-auth', 'identity.auth', 'identity.require-actor-type:admin'])
        ->prefix('admin/auth')
        ->group(static function (): void {
            Route::post('change-password', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminChangePasswordController::class, 'changePassword']);
        });

    // -------------------------------------------------------------------------
    // E04: Admin Routes — protected by identity.auth + actor-type + permissions
    // -------------------------------------------------------------------------
    Route::middleware([
        'throttle:identity-admin',
        'identity.auth',
        'identity.require-actor-type:admin',
    ])->prefix('admin')->group(static function (): void {

        // Session management
        Route::middleware('identity.require-permission:session.admin.revoke')
            ->get('sessions', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminSessionController::class, 'index']);

        Route::middleware('identity.require-permission:session.admin.revoke')
            ->post('sessions/{session_id}/revoke', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminSessionController::class, 'revoke']);

        // Configuration (existing E01 endpoints, now permission-gated)
        Route::middleware('identity.require-permission:configuration.policy.view')
            ->get('platform/configuration/security', [\App\Modules\Identity\Infrastructure\Http\Controllers\ConfigurationController::class, 'index']);

        Route::middleware(['identity.require-permission:configuration.policy.update', \App\Http\Middleware\IdempotencyMiddleware::class])
            ->put('platform/configuration/security/{key}', [\App\Modules\Identity\Infrastructure\Http\Controllers\ConfigurationController::class, 'update'])
            ->where('key', '.+');

        // Roles (E04 — Phase 4, 8, 9, 10)
        Route::middleware('identity.require-permission:admin.role.read')
            ->get('roles', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleController::class, 'index']);
        Route::middleware('identity.require-permission:admin.role.read')
            ->get('roles/{id}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleController::class, 'show']);
        Route::middleware('identity.require-permission:admin.role.create')
            ->post('roles', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.manage')
            ->delete('roles/{id}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleController::class, 'destroy']);
        Route::middleware('identity.require-permission:admin.role.manage')
            ->post('roles/{id}/permissions', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRolePermissionController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.manage')
            ->delete('roles/{id}/permissions/{permissionId}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRolePermissionController::class, 'destroy']);

        // Permissions (E04 — Phase 8)
        Route::middleware('identity.require-permission:admin.permission.read')
            ->get('permissions', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminPermissionController::class, 'index']);

        // Admin Users (E04 — Phases 5, 6, 7)
        Route::middleware(['identity.require-permission:admin.user.create', \App\Http\Middleware\IdempotencyMiddleware::class])
            ->post('users', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminUserController::class, 'store']);
        Route::middleware('identity.require-permission:admin.user.read')
            ->get('users', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminUserController::class, 'index']);
        Route::middleware('identity.require-permission:admin.user.read')
            ->get('users/{id}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminUserController::class, 'show']);
        Route::middleware('identity.require-permission:admin.user.deactivate')
            ->patch('users/{id}/status', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminUserController::class, 'updateStatus']);

        // Role assignments on users (E04 — Phase 11)
        Route::middleware('identity.require-permission:admin.user.read')
            ->get('users/{id}/roles', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleAssignmentController::class, 'index']);
        Route::middleware('identity.require-permission:admin.role.assign')
            ->post('users/{id}/roles', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleAssignmentController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.assign')
            ->delete('users/{id}/roles/{roleId}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminRoleAssignmentController::class, 'destroy']);
    });

    // -------------------------------------------------------------------------
    // Phase 11: PIN & Credential Management (T111)
    // -------------------------------------------------------------------------

    // PIN set: accepts X-Setup-Token (unauthenticated) OR Bearer JWT (optional auth).
    // identity.auth.optional attaches identity.user_id when a valid Bearer is present
    // but passes through without rejecting if absent (for the setup_token flow).
    Route::middleware(['throttle:identity-public', 'identity.auth.optional'])->group(static function (): void {
        Route::post('auth/pin/set', [\App\Modules\Identity\Infrastructure\Http\Controllers\PinController::class, 'create']);
    });
    Route::post('auth/pin/reset/initiate', [\App\Modules\Identity\Infrastructure\Http\Controllers\PinController::class, 'resetInitiate'])
        ->middleware('throttle:identity-public');
    Route::post('auth/pin/reset/complete', [\App\Modules\Identity\Infrastructure\Http\Controllers\PinController::class, 'resetComplete'])
        ->middleware('throttle:identity-public');
    Route::post('auth/pin/unblock',        [\App\Modules\Identity\Infrastructure\Http\Controllers\PinController::class, 'unblock'])
        ->middleware('throttle:identity-public');

    // Authenticated PIN change (requires valid Bearer JWT)
    Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {
        Route::post('auth/pin/change', [\App\Modules\Identity\Infrastructure\Http\Controllers\PinController::class, 'change']);
    });

    // -------------------------------------------------------------------------
    // Phase 12: MFA Enrollment & Verification (T119)
    // -------------------------------------------------------------------------

    // MFA verify: no auth required — caller presents mfa_token issued by login gate
    Route::post('auth/mfa/verify', [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'verify'])
        ->middleware('throttle:identity-public');

    // MFA management: requires valid Bearer JWT
    Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {
        Route::get('auth/mfa/methods',               [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'methods']);
        Route::post('auth/mfa/enroll/totp',          [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'enrollTotp']);
        Route::post('auth/mfa/enroll/totp/confirm',  [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'confirmTotpEnrollment']);
        Route::post('auth/mfa/enroll/fido2',         [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'enrollFido2']);
        Route::post('auth/mfa/enroll/fido2/confirm', [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'confirmFido2Enrollment']);
        Route::post('auth/mfa/recovery-codes',       [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'recoveryCodes']);
        Route::post('auth/mfa/disable',              [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'disable']);
    });

    // -------------------------------------------------------------------------
    // Phase 13: Step-Up Authentication (T128)
    // -------------------------------------------------------------------------

    // Both step-up endpoints require a valid Bearer JWT
    Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {
        Route::post('auth/step-up/initiate', [\App\Modules\Identity\Infrastructure\Http\Controllers\StepUpController::class, 'initiate']);
        Route::post('auth/step-up/verify',   [\App\Modules\Identity\Infrastructure\Http\Controllers\StepUpController::class, 'verify']);
    });

    // -------------------------------------------------------------------------
    // Phase 14: Service Identity (T138)
    // -------------------------------------------------------------------------
    // No Bearer JWT required — service authenticates via X-Service-Secret header (TBD mechanism).
    // Idempotency enforced: callers MUST supply Idempotency-Key header for replay protection.
    Route::middleware([\App\Http\Middleware\IdempotencyMiddleware::class])->group(static function (): void {
        Route::post('auth/service/token', [\App\Modules\Identity\Infrastructure\Http\Controllers\ServiceTokenController::class, 'issue']);
    });

    // Phase 15: Configuration Admin API — now handled under E04 admin group above.

    // -------------------------------------------------------------------------
    // E03: Device & Session Management — Customer/Agent routes (T032, T040, T064, T070, T075, T080, T089)
    // -------------------------------------------------------------------------
    Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {
        // Fingerprint generation — no idempotency (read-only computation)
        Route::post('devices/fingerprint', [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'fingerprint']);

        // Device list & detail — read-only
        Route::get('devices',      [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'index']);
        Route::get('devices/{id}', [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'show']);

        // State-changing operations require Idempotency-Key (enforced in handler)
        Route::post('devices',                       [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'register']);
        Route::post('devices/{id}/trust',            [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'trust']);
        Route::post('devices/{id}/acknowledge',      [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'acknowledge']);
        Route::post('devices/{id}/block',            [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'block']);
        Route::delete('devices/{id}',                [\App\Modules\Identity\Infrastructure\Http\Controllers\DeviceManagementController::class, 'remove']);
    });

    // -------------------------------------------------------------------------
    // E03: Device & Session Management — Admin routes (T094, T135)
    // Now protected by E04 permission middleware.
    // -------------------------------------------------------------------------
    Route::middleware([
        'throttle:identity-admin',
        'identity.auth',
        'identity.require-actor-type:admin',
    ])->prefix('admin')->group(static function (): void {
        Route::middleware('identity.require-permission:device.admin.read')
            ->get('users/{userId}/devices', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController::class, 'index']);

        Route::middleware('identity.require-permission:device.admin.read')
            ->get('devices/{id}/security-events', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController::class, 'securityEvents']);

        Route::middleware('identity.require-permission:device.admin.remove')
            ->delete('devices/{id}', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController::class, 'remove']);

        Route::middleware('identity.require-permission:device.admin.block')
            ->post('devices/{id}/block', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController::class, 'block']);

        Route::middleware('identity.require-permission:device.admin.unblock')
            ->post('devices/{id}/unblock', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminDeviceController::class, 'unblock']);
    });
});
