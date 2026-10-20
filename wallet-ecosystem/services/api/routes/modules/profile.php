<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E02 User & Profile Management Routes
|--------------------------------------------------------------------------
| All E02 endpoints are prefixed /v1/ and registered here.
| Routes are loaded by ProfileServiceProvider::boot().
|
| Middleware stacks:
|   authenticated:  identity.auth, throttle:profile-user
|   photo-upload:   identity.auth, throttle:profile-photo
|   admin:          identity.auth, throttle:profile-admin, profile.permission:{permission}
|
| Routes are added incrementally as controller phases complete (T033, T045, T052, ...).
*/

Route::prefix('v1')->middleware(['profile.latency:profile.api'])->group(static function (): void {

    // -------------------------------------------------------------------------
    // Phase 3: Profile View (T033)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-user'])->group(static function (): void {
        Route::get('users/profile', [\App\Modules\Profile\Infrastructure\Http\Controllers\ProfileController::class, 'show']);
    });

    // -------------------------------------------------------------------------
    // Phase 4: Personal Info Update (T045)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-user'])->group(static function (): void {
        Route::put('users/profile/personal', [\App\Modules\Profile\Infrastructure\Http\Controllers\ProfileController::class, 'updatePersonal']);
    });

    // Admin routes: identity.auth + admin-specific throttle + named permission gate (T182, T184)
    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:profile.read'])->group(static function (): void {
        Route::get('admin/users/{user_id}/profile', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController::class, 'show']);
    });

    // -------------------------------------------------------------------------
    // Phase 22: Admin Dashboard Overview (T179)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:profile.read'])->group(static function (): void {
        Route::get('admin/profile/dashboard/summary', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminDashboardController::class, 'summary']);
    });

    // Admin profile update: requires profile.update permission (T044, T045)
    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:profile.update'])->group(static function (): void {
        Route::put('admin/users/{user_id}/profile/personal', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController::class, 'updatePersonal']);
    });

    // -------------------------------------------------------------------------
    // Phase 5: Profile Photo Upload (T052)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-photo'])->group(static function (): void {
        Route::post('users/profile/photo', [\App\Modules\Profile\Infrastructure\Http\Controllers\ProfilePhotoController::class, 'store']);
    });

    // -------------------------------------------------------------------------
    // Phase 8: Account Status View (T083)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-user'])->group(static function (): void {
        Route::get('users/profile/status', [\App\Modules\Profile\Infrastructure\Http\Controllers\AccountStatusController::class, 'show']);
    });

    // Admin: account status + history with actor_id; requires profile.read (T081, T083)
    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:profile.read'])->group(static function (): void {
        Route::get('admin/users/{user_id}/profile/status', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController::class, 'status']);
    });

    // -------------------------------------------------------------------------
    // Phase 9: Account Suspension & Reactivation (T090)
    // NOTE: suspend and reactivate routes moved to E19 Admin Portal (routes/modules/admin.php)
    // at POST /v1/admin/users/{userId}/suspend|reactivate with identity.require-permission:admin.write.
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Phase 10: Closure Request & Cancellation (T098)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-user'])->group(static function (): void {
        Route::post('users/profile/closure/request', [\App\Modules\Profile\Infrastructure\Http\Controllers\ClosureController::class, 'request']);
        Route::delete('users/profile/closure/request', [\App\Modules\Profile\Infrastructure\Http\Controllers\ClosureController::class, 'cancel']);
    });

    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:account.reactivate'])->group(static function (): void {
        Route::post('admin/users/{user_id}/cancel-closure', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController::class, 'cancelClosure']);
    });

    // -------------------------------------------------------------------------
    // Phase 11/12: Permanent Account Closure (T109)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-admin', 'profile.permission:account.close'])->group(static function (): void {
        Route::post('admin/users/{user_id}/close', [\App\Modules\Profile\Infrastructure\Http\Controllers\AdminProfileController::class, 'close']);
    });

    // -------------------------------------------------------------------------
    // Phase 6: Email Change Initiation & Verification (T063)
    // Phase 7: Mobile Change Initiation & Verification (T075)
    // Stricter per-hour throttle for OTP-triggering endpoints (T188; spec §9 security).
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-contact-change'])->group(static function (): void {
        Route::post('users/profile/email/change', [\App\Modules\Profile\Infrastructure\Http\Controllers\ContactChangeController::class, 'initiateEmail']);
        Route::post('users/profile/email/verify', [\App\Modules\Profile\Infrastructure\Http\Controllers\ContactChangeController::class, 'verifyEmail']);
        Route::post('users/profile/mobile/change', [\App\Modules\Profile\Infrastructure\Http\Controllers\ContactChangeController::class, 'initiateMobile']);
        Route::post('users/profile/mobile/verify', [\App\Modules\Profile\Infrastructure\Http\Controllers\ContactChangeController::class, 'verifyMobile']);
    });

    // -------------------------------------------------------------------------
    // Phase 13: Personal Data Export (T116)
    // -------------------------------------------------------------------------
    Route::middleware(['identity.auth', 'throttle:profile-user'])->group(static function (): void {
        Route::post('users/profile/data-export', [\App\Modules\Profile\Infrastructure\Http\Controllers\DataExportController::class, 'request']);
        Route::get('users/profile/data-export/{export_id}', [\App\Modules\Profile\Infrastructure\Http\Controllers\DataExportController::class, 'status']);
    });

});
