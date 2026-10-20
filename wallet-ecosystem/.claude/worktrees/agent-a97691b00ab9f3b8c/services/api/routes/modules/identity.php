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

    // Phase 10: Admin Session Revocation (T104)
    // identity.auth validates the Bearer JWT; actor_type=ADMIN check is enforced in AdminSessionController
    // until E04 (Roles/Permissions) ships its authorization middleware.
    Route::middleware(['throttle:identity-admin', 'identity.auth'])->group(static function (): void {
        Route::post('admin/sessions/{session_id}/revoke', [\App\Modules\Identity\Infrastructure\Http\Controllers\AdminSessionController::class, 'revoke']);
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
        Route::post('auth/mfa/enroll/totp',          [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'enrollTotp']);
        Route::post('auth/mfa/enroll/totp/confirm',  [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'confirmTotpEnrollment']);
        Route::post('auth/mfa/enroll/fido2',         [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'enrollFido2']);
        Route::post('auth/mfa/enroll/fido2/confirm', [\App\Modules\Identity\Infrastructure\Http\Controllers\MfaController::class, 'confirmFido2Enrollment']);
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

    // -------------------------------------------------------------------------
    // Phase 15: Configuration Admin API (T146)
    // -------------------------------------------------------------------------
    // GET: read-only, no idempotency needed; PUT: state-changing, idempotency required.
    // Both endpoints require a valid admin Bearer JWT (identity.auth).
    Route::middleware(['throttle:identity-admin', 'identity.auth'])->group(static function (): void {
        Route::get('admin/platform/configuration/security', [\App\Modules\Identity\Infrastructure\Http\Controllers\ConfigurationController::class, 'index']);

        Route::middleware([\App\Http\Middleware\IdempotencyMiddleware::class])->group(static function (): void {
            Route::put('admin/platform/configuration/security/{key}', [\App\Modules\Identity\Infrastructure\Http\Controllers\ConfigurationController::class, 'update'])
                ->where('key', '.+');  // allow dots in key name (e.g., auth.otp.ttl)
        });
    });
});
