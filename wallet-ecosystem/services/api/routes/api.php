<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Middleware stack (applied in bootstrap/app.php):
|   TrustProxies → SecurityHeaders → CorrelationId → RequestLogging
|   → Authentication → Idempotency → Throttle → ResponseEnvelope → ResponseLogging
|
| Module route files are registered exclusively by each module's ServiceProvider
| via $this->loadRoutesFrom(base_path('routes/modules/{module}.php')) per FR-176.
|
| Health probe routes (/health, /ready, /startup) are registered at the root
| level (no /api prefix) via bootstrap/app.php withRouting(then:…) per FR-052.
|
*/
if (env('P26_E2E_FIXTURE_APPROVALS') === '1' && env('APP_ENV') === 'testing') {
    Route::get('/p26-e2e/database', static function () {
        $row = DB::connection('platform')->selectOne('SELECT current_database() AS database_name');

        return response()->json([
            'database' => $row->database_name ?? null,
            'configured_database' => config('database.connections.platform.database'),
            'host' => config('database.connections.platform.host'),
            'port' => (string) config('database.connections.platform.port'),
        ]);
    });
}
