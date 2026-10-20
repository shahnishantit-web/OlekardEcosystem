<?php

declare(strict_types=1);

use App\Modules\Integration\Infrastructure\Http\Controllers\CreateApiClientController;
use App\Modules\Integration\Infrastructure\Http\Controllers\GetApiClientController;
use App\Modules\Integration\Infrastructure\Http\Controllers\GetIntegrationController;
use App\Modules\Integration\Infrastructure\Http\Controllers\GetProviderCallLogController;
use App\Modules\Integration\Infrastructure\Http\Controllers\GetWebhookEventController;
use App\Modules\Integration\Infrastructure\Http\Controllers\ListApiClientsController;
use App\Modules\Integration\Infrastructure\Http\Controllers\ListIntegrationsController;
use App\Modules\Integration\Infrastructure\Http\Controllers\ListProviderCallLogsController;
use App\Modules\Integration\Infrastructure\Http\Controllers\ListWebhookEventsController;
use App\Modules\Integration\Infrastructure\Http\Controllers\ReceiveWebhookController;
use App\Modules\Integration\Infrastructure\Http\Controllers\RegisterIntegrationController;
use App\Modules\Integration\Infrastructure\Http\Controllers\RequeueWebhookEventController;
use App\Modules\Integration\Infrastructure\Http\Controllers\RotateApiClientSecretController;
use App\Modules\Integration\Infrastructure\Http\Controllers\SetDefaultIntegrationController;
use App\Modules\Integration\Infrastructure\Http\Controllers\UpdateApiClientStatusController;
use App\Modules\Integration\Infrastructure\Http\Controllers\UpdateIntegrationController;
use App\Modules\Integration\Infrastructure\Http\Controllers\UpdateIntegrationStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E25 API & Integration Platform Routes
|--------------------------------------------------------------------------
| All admin endpoints are under /v1/ (api/ prefix from bootstrap).
| Webhook receiver is public (no identity.auth).
| Auth: identity.auth (JWT) for admin endpoints.
|       integration.client-auth (Bearer base64) for M2M endpoints.
| Routes are loaded by IntegrationServiceProvider::boot().
*/

// ── Public webhook receiver (US3) — no identity.auth ────────────────────────
Route::prefix('v1')->middleware(['integration.correlation-id', 'integration.rate-limit'])->group(static function (): void {
    Route::post('/webhooks/{providerSlug}', ReceiveWebhookController::class);
});

// ── Admin: API client management (US1) ──────────────────────────────────────
Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {
    Route::middleware(['identity.require-permission:integrations.manage'])->group(static function (): void {
        Route::get('/integrations/clients', ListApiClientsController::class);
        Route::post('/integrations/clients', CreateApiClientController::class);
        Route::get('/integrations/clients/{id}', GetApiClientController::class)->whereUuid('id');
        Route::post('/integrations/clients/{id}/rotate-secret', RotateApiClientSecretController::class)->whereUuid('id');
        Route::patch('/integrations/clients/{id}/status', UpdateApiClientStatusController::class)->whereUuid('id');
    });

    // ── Admin: Provider integration management (US2) ───────────────────────
    Route::middleware(['identity.require-permission:integrations.manage'])->group(static function (): void {
        Route::get('/integrations/providers', ListIntegrationsController::class);
        Route::post('/integrations/providers', RegisterIntegrationController::class);
        Route::get('/integrations/providers/{id}', GetIntegrationController::class)->whereUuid('id');
        Route::patch('/integrations/providers/{id}', UpdateIntegrationController::class)->whereUuid('id');
        Route::patch('/integrations/providers/{id}/status', UpdateIntegrationStatusController::class)->whereUuid('id');
        Route::post('/integrations/providers/{id}/set-default', SetDefaultIntegrationController::class)->whereUuid('id');
        Route::get('/integrations/providers/call-logs', ListProviderCallLogsController::class);
        Route::get('/integrations/providers/call-logs/{id}', GetProviderCallLogController::class)->whereUuid('id');
    });

    // ── Admin: Webhook monitoring (US3) ────────────────────────────────────
    Route::middleware(['identity.require-permission:integrations.manage'])->group(static function (): void {
        Route::get('/integrations/webhooks', ListWebhookEventsController::class);
        Route::get('/integrations/webhooks/{id}', GetWebhookEventController::class)->whereUuid('id');
        Route::post('/integrations/webhooks/{id}/requeue', RequeueWebhookEventController::class)->whereUuid('id');
    });
});
