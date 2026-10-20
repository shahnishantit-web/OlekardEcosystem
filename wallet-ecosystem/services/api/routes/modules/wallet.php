<?php

declare(strict_types=1);

use App\Modules\Wallet\Infrastructure\Http\Controllers\WalletController;
use App\Modules\Wallet\Infrastructure\Http\Controllers\WalletLimitController;
use App\Modules\Wallet\Infrastructure\Http\Controllers\WalletPocketController;
use App\Modules\Wallet\Infrastructure\Http\Controllers\WalletStatusHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E06 Wallet Management Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
| Routes loaded by WalletServiceProvider::boot().
*/

Route::prefix('v1/wallets')->middleware(['identity.auth'])->group(static function (): void {

    // ── Wallet CRUD ──────────────────────────────────────────────────────────

    Route::post('', [WalletController::class, 'create'])
        ->middleware('identity.require-permission:wallet.create');

    // Must be before /{id} to avoid routing conflict
    Route::get('my', [WalletController::class, 'my'])
        ->middleware('identity.require-permission:wallet.read');

    Route::get('{id}', [WalletController::class, 'show'])
        ->middleware('identity.require-permission:wallet.read');

    // ── Wallet Lifecycle ─────────────────────────────────────────────────────

    Route::patch('{id}/status', [WalletController::class, 'updateStatus'])
        ->middleware('identity.require-permission:wallet.status.manage');

    Route::post('{id}/freeze', [WalletController::class, 'freeze'])
        ->middleware('identity.require-permission:wallet.freeze');

    Route::post('{id}/unfreeze', [WalletController::class, 'unfreeze'])
        ->middleware('identity.require-permission:wallet.unfreeze');

    Route::post('{id}/close', [WalletController::class, 'close'])
        ->middleware('identity.require-permission:wallet.close');

    // ── Wallet Limits ────────────────────────────────────────────────────────

    Route::get('{id}/limits', [WalletLimitController::class, 'index'])
        ->middleware('identity.require-permission:wallet.read');

    Route::put('{id}/limits', [WalletLimitController::class, 'update'])
        ->middleware('identity.require-permission:wallet.limits.manage');

    // ── Currency Pockets ─────────────────────────────────────────────────────

    Route::get('{id}/pockets', [WalletPocketController::class, 'index'])
        ->middleware('identity.require-permission:wallet.read');

    Route::post('{id}/pockets', [WalletPocketController::class, 'store'])
        ->middleware('identity.require-permission:wallet.pocket.create');

    Route::get('{id}/pockets/{code}/balance', [WalletPocketController::class, 'balance'])
        ->middleware('identity.require-permission:wallet.balance.read');

    // ── Status History ────────────────────────────────────────────────────────

    Route::get('{id}/status-history', [WalletStatusHistoryController::class, 'index'])
        ->middleware('identity.require-permission:wallet.read');
});
