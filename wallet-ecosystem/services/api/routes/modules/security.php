<?php

declare(strict_types=1);

use App\Modules\Security\Http\Controllers\AdminSecurityManagementController;
use App\Modules\Security\Http\Controllers\SecurityAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E26 Security Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:security.admin.
| Routes are loaded by SecurityServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    Route::prefix('security')->group(static function (): void {
        // Read-only endpoints — requires security.findings.read
        Route::middleware(['identity.require-permission:security.findings.read'])->group(static function (): void {
            Route::get('dashboard', [SecurityAdminController::class, 'dashboard']);
            Route::get('findings', [SecurityAdminController::class, 'index']);
            Route::get('findings/{id}', [SecurityAdminController::class, 'show']);
        });

        // Mutation endpoints — requires security.findings.write
        Route::middleware(['identity.require-permission:security.findings.write'])->group(static function (): void {
            Route::post('findings', [SecurityAdminController::class, 'store']);
            Route::patch('findings/{id}/status', [SecurityAdminController::class, 'updateStatus']);
        });
    });
});

Route::prefix('v1/admin')->middleware(['identity.auth'])->group(static function (): void {
    $c = AdminSecurityManagementController::class;
    Route::get('platform/security-config', [$c, 'config'])->middleware('identity.require-permission:security.config.read');
    Route::patch('platform/security-config', [$c, 'propose'])->middleware('identity.require-permission:security.config.write');
    Route::post('platform/security-config/{id}/approve', [$c, 'approve'])->whereUuid('id')->middleware(['identity.require-permission:security.config.write', 'identity.require-permission:configuration.approve']);
    Route::get('security/findings/{id}', [$c, 'finding'])->whereUuid('id')->middleware('identity.require-permission:security.findings.read');
    Route::patch('security/findings/{id}', [$c, 'updateFinding'])->whereUuid('id')->middleware('identity.require-permission:security.findings.write');
    Route::get('security/events/export', [$c, 'export'])->middleware('identity.require-permission:security.events.export');
    Route::get('security/events', [$c, 'events'])->middleware('identity.require-permission:security.events.read');
});
