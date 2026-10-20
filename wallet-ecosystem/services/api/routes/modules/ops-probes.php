<?php

declare(strict_types=1);

use App\Modules\Ops\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E30 DevOps & Operations — Unauthenticated Health Probes
|--------------------------------------------------------------------------
| These routes MUST NOT have identity.auth middleware.
| Infrastructure (k8s, load-balancers, monitoring) polls these without tokens.
*/

Route::prefix('v1/ops')->group(static function (): void {
    Route::get('health',       [HealthController::class, 'health']);   // US-OPS-003
    Route::get('health/ready', [HealthController::class, 'ready']);    // US-OPS-004
    Route::get('health/live',  [HealthController::class, 'live']);     // US-OPS-005
});
