<?php

declare(strict_types=1);

use App\Http\Controllers\ConfigurationController;
use App\Modules\Platform\Domain\Permissions\PlatformPermissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'correlation'])->prefix('v1')->group(function () {
    Route::get('/configuration/values/{key}', [ConfigurationController::class, 'show'])
        ->where('key', '.+');

    Route::post('/configuration/values', [ConfigurationController::class, 'store'])
        ->middleware('can:'.PlatformPermissions::CONFIGURATION_UPDATE);

    Route::patch('/configuration/values/{id}/activate', [ConfigurationController::class, 'activate'])
        ->middleware('can:'.PlatformPermissions::CONFIGURATION_UPDATE);
});
