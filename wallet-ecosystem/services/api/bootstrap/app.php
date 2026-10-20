<?php

use App\Exceptions\Handler;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Middleware\ResponseEnvelopeMiddleware;
use App\Http\Middleware\ResponseLoggingMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Modules\Ops\Infrastructure\Http\Middleware\MetricsCollectionMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Health probe routes at root level (no /api prefix) per FR-052, FR-053, FR-054.
            \Illuminate\Support\Facades\Route::middleware([
                \App\Http\Middleware\CorrelationIdMiddleware::class,
            ])->group(function () {
                \Illuminate\Support\Facades\Route::get('/health', [\App\Http\Controllers\HealthController::class, 'health'])->name('health');
                \Illuminate\Support\Facades\Route::get('/ready', [\App\Http\Controllers\HealthController::class, 'ready'])->name('ready');
                \Illuminate\Support\Facades\Route::get('/startup', [\App\Http\Controllers\HealthController::class, 'startup'])->name('startup');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            SecurityHeadersMiddleware::class,
            CorrelationIdMiddleware::class,
            MetricsCollectionMiddleware::class,
            RequestLoggingMiddleware::class,
        ]);
        $middleware->api(append: [
            ResponseEnvelopeMiddleware::class,
            ResponseLoggingMiddleware::class,
        ]);

        // E27: idempotency middleware aliases.
        // Use 'idempotency' for opt-in; 'idempotency:require' for financial mutation endpoints (FR-001).
        $middleware->alias([
            'idempotency' => IdempotencyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Handler::register($exceptions);
    })->create();
