<?php

declare(strict_types=1);

use App\Modules\Qr\Infrastructure\Http\Controllers\QrCodeController;
use App\Modules\Qr\Infrastructure\Http\Controllers\QrPaymentController;
use Illuminate\Support\Facades\Route;

// ─── Merchant endpoints — QR code management ──────────────────────────────────
Route::prefix('api/v1/qr-codes')
    ->middleware(['identity.auth', 'identity.require-permission:qr.generate'])
    ->group(function (): void {
        Route::post('/', [QrCodeController::class, 'generate']);
    });

Route::prefix('api/v1/qr-codes')
    ->middleware(['identity.auth', 'identity.require-permission:qr.manage'])
    ->group(function (): void {
        Route::get('/',        [QrCodeController::class, 'index']);
        Route::get('/{id}',    [QrCodeController::class, 'show']);
        Route::delete('/{id}', [QrCodeController::class, 'deactivate']);
    });

// ─── Customer endpoints — QR payment flow ─────────────────────────────────────
Route::prefix('api/v1/qr-payments')
    ->middleware(['identity.auth', 'identity.require-permission:qr.pay'])
    ->group(function (): void {
        Route::post('/resolve', [QrPaymentController::class, 'resolve']);
        Route::post('/quote',   [QrPaymentController::class, 'quote']);
        Route::post('/',        [QrPaymentController::class, 'confirm'])
            ->middleware('idempotency:require');
        Route::get('/{id}',     [QrPaymentController::class, 'show']);
        Route::get('/{id}/receipt', [QrPaymentController::class, 'receipt']);
    });
