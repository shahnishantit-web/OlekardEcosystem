<?php

declare(strict_types=1);

use App\Http\Controllers\ConfigurationController;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Modules\Platform\Domain\Permissions\PlatformPermissions;
use App\Modules\Platform\Http\Controllers\Admin\ConfigurationChangeRequestController;
use App\Modules\Platform\Http\Controllers\Admin\ConfigurationKeyAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['identity.auth', CorrelationIdMiddleware::class])->prefix('v1')->group(function () {
    Route::get('/configuration/values/{key}', [ConfigurationController::class, 'show'])
        ->where('key', '.+');

    Route::post('/configuration/values', [ConfigurationController::class, 'store'])
        ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_UPDATE);

    Route::patch('/configuration/values/{id}/activate', [ConfigurationController::class, 'activate'])
        ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_UPDATE);

    // E28 Admin routes — Configuration Management
    Route::prefix('admin/configuration')->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_READ)->group(function () {
        // Key browser (read-only, audit-logged)
        Route::get('/keys', [ConfigurationKeyAdminController::class, 'index']);

        // Version history
        Route::get('/keys/{key}/versions', [ConfigurationKeyAdminController::class, 'versions'])->where('key', '[^/]+');
        Route::get('/keys/{key}', [ConfigurationKeyAdminController::class, 'show'])->where('key', '[^/]+');
    });

    // Change requests — write requires CONFIGURATION_WRITE, approve/reject/withdraw handled per-action
    Route::prefix('admin/configuration/change-requests')->group(function () {
        Route::get('/', [ConfigurationChangeRequestController::class, 'index'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_READ);

        Route::post('/', [ConfigurationChangeRequestController::class, 'store'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_WRITE);

        Route::get('/{id}', [ConfigurationChangeRequestController::class, 'show'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_READ);

        Route::post('/{id}/approve', [ConfigurationChangeRequestController::class, 'approve'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_APPROVE);

        Route::post('/{id}/reject', [ConfigurationChangeRequestController::class, 'reject'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_APPROVE);

        Route::post('/{id}/withdraw', [ConfigurationChangeRequestController::class, 'withdraw'])
            ->middleware('identity.require-permission:'.PlatformPermissions::CONFIGURATION_WRITE);
    });
});
