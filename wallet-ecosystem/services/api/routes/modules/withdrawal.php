<?php

declare(strict_types=1);

use App\Modules\Withdrawal\Http\Controllers\AdminWithdrawalController;
use App\Modules\Withdrawal\Http\Controllers\WithdrawalCallbackController;
use App\Modules\Withdrawal\Infrastructure\Http\Controllers\WithdrawalController;
use App\Modules\Withdrawal\Http\Controllers\WithdrawalReceiptController;
use Illuminate\Support\Facades\Route;

if (app()->environment(['local', 'testing'])) {
    Route::post('api/v1/dev/mock-bank/withdrawals/{id}/complete',
        [\App\Modules\Withdrawal\Infrastructure\Http\Controllers\DevMockWithdrawalController::class, 'complete'])
        ->middleware(['identity.auth', 'identity.require-permission:withdrawal.initiate', 'idempotency:require']);
}

// Provider callbacks — no authentication required
Route::prefix('api/v1/withdrawals/callbacks')->group(function (): void {
    Route::post('/{provider}', [WithdrawalCallbackController::class, 'handle']);
});

// Customer-facing endpoints — authenticated
Route::prefix('api/v1/withdrawals')->middleware(['identity.auth'])->group(function (): void {
    Route::post('/', [WithdrawalController::class, 'initiate'])
        ->middleware(['identity.require-permission:withdrawal.initiate', 'idempotency:require']);

    Route::get('/', [WithdrawalController::class, 'index'])
        ->middleware('identity.require-permission:withdrawal.read');

    // Static receipt-by-reference route MUST precede dynamic /{id} route
    Route::get('/receipts/{receiptReference}', [WithdrawalReceiptController::class, 'showByReference'])
        ->middleware('identity.require-permission:withdrawal.read');

    Route::get('/{id}', [WithdrawalController::class, 'show'])
        ->middleware('identity.require-permission:withdrawal.read');

    Route::post('/{id}/confirm', [WithdrawalController::class, 'confirm'])
        ->middleware(['identity.require-permission:withdrawal.initiate', 'idempotency:require']);

    Route::post('/{id}/cancel', [WithdrawalController::class, 'cancel'])
        ->middleware('identity.require-permission:withdrawal.initiate');

    Route::get('/{id}/receipt', [WithdrawalReceiptController::class, 'show'])
        ->middleware('identity.require-permission:withdrawal.read');
});

// Admin endpoints
Route::prefix('api/v1/admin/withdrawals')->middleware(['identity.auth', 'identity.require-permission:withdrawal.admin'])->group(function (): void {
    Route::get('/', [AdminWithdrawalController::class, 'index']);
    Route::get('/{id}', [AdminWithdrawalController::class, 'show']);
    Route::post('/{id}/force-fail', [AdminWithdrawalController::class, 'forceFail']);
});
