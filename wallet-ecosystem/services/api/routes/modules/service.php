<?php

declare(strict_types=1);

use App\Modules\Service\Infrastructure\Http\Controllers\AdminServiceResolutionController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServiceCallbackController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServiceCatalogController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServiceDiscoveryController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServicePaymentController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServiceReceiptController;
use App\Modules\Service\Infrastructure\Http\Controllers\ServiceValidationController;
use Illuminate\Support\Facades\Route;

// ─── Admin endpoints — service catalog management ─────────────────────────────
Route::prefix('api/v1/services/admin')
    ->middleware(['identity.auth', 'identity.require-permission:service.manage'])
    ->group(function (): void {
        // Admin transaction resolution (must be before {id} to avoid collision)
        Route::post('/transactions/{txId}/resolve', [AdminServiceResolutionController::class, 'resolve']);

        // Service catalog CRUD
        Route::get('/',         [ServiceCatalogController::class, 'index']);
        Route::post('/',        [ServiceCatalogController::class, 'store']);
        Route::get('/{id}',     [ServiceCatalogController::class, 'show']);
        Route::patch('/{id}',   [ServiceCatalogController::class, 'update']);
        Route::post('/{id}/deactivate', [ServiceCatalogController::class, 'deactivate']);
        Route::post('/{id}/reactivate', [ServiceCatalogController::class, 'reactivate']);
    });

// ─── Customer endpoints — payment status and receipt (before /{id} pattern) ──
Route::prefix('api/v1/services/payments')
    ->middleware(['identity.auth', 'identity.require-permission:service.list'])
    ->group(function (): void {
        Route::get('/', [ServicePaymentController::class, 'index']);
        Route::get('/{txId}',         [ServicePaymentController::class, 'show']);
        Route::get('/{txId}/receipt', [ServiceReceiptController::class, 'show']);
    });

// ─── Customer endpoints — service discovery ───────────────────────────────────
Route::prefix('api/v1/services')
    ->middleware(['identity.auth', 'identity.require-permission:service.list'])
    ->group(function (): void {
        Route::get('/',      [ServiceDiscoveryController::class, 'index']);
        Route::get('/{id}',  [ServiceDiscoveryController::class, 'show']);
    });

// ─── Customer endpoints — pre-payment validation & payment initiation ─────────
Route::prefix('api/v1/services')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::post('/{id}/validate-reference', [ServiceValidationController::class, 'validateReference'])
            ->middleware('identity.require-permission:service.list');

        Route::post('/{id}/calculate-fee', [ServiceValidationController::class, 'calculateFee'])
            ->middleware('identity.require-permission:service.list');

        Route::post('/{id}/payments', [ServicePaymentController::class, 'store'])
            ->middleware(['identity.require-permission:service.pay', 'idempotency:require']);
    });

// ─── Provider callbacks (public — signature-verified inline) ──────────────────
Route::prefix('api/v1/services')
    ->group(function (): void {
        Route::post('/callback/{provider}', [ServiceCallbackController::class, 'handle']);
    });
