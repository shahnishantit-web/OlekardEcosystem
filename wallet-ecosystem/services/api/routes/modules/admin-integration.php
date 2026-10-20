<?php

declare(strict_types=1);
use App\Modules\Integration\Infrastructure\Http\Controllers\AdminIntegrationController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->middleware(['identity.auth', 'identity.require-permission:integrations.manage'])->group(function (): void {
    Route::get('integrations/api-clients', [C::class, 'clients']);
    Route::post('integrations/api-clients', [C::class, 'create']);
    Route::get('integrations/api-clients/{id}', [C::class, 'show'])->whereUuid('id');
    Route::post('integrations/api-clients/{id}/rotate', [C::class, 'rotate'])->whereUuid('id')->middleware('throttle:10,1');
    Route::delete('integrations/api-clients/{id}', [C::class, 'revoke'])->whereUuid('id');
    Route::get('integrations/providers', [C::class, 'providers']);
    Route::get('integrations/providers/{id}/health', [C::class, 'health'])->whereUuid('id');
    Route::get('webhooks/deliveries', [C::class, 'deliveries']);
    Route::post('webhooks/deliveries/{id}/retry', [C::class, 'retry'])->whereUuid('id')->middleware('throttle:20,1');
});
