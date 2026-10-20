<?php

declare(strict_types=1);

use App\Modules\MultiCurrency\Http\Controllers\CurrencyController;
use App\Modules\MultiCurrency\Http\Controllers\CurrencyWalletController;
use App\Modules\MultiCurrency\Http\Controllers\FxFeeController;
use App\Modules\MultiCurrency\Http\Controllers\FxQuoteController;
use App\Modules\MultiCurrency\Http\Controllers\FxTransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E29 Multi-Currency Wallet — Customer Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
*/

Route::prefix('v1/multi-currency')->middleware(['identity.auth'])->group(static function (): void {

    // ── Currencies ───────────────────────────────────────────────────────────
    Route::get('currencies', [CurrencyController::class, 'index']);

    // ── Currency Wallets ─────────────────────────────────────────────────────
    Route::post('wallets', [CurrencyWalletController::class, 'store'])
        ->middleware('identity.require-permission:multi_currency.create_wallet');

    Route::get('wallets', [CurrencyWalletController::class, 'index'])
        ->middleware('identity.require-permission:multi_currency.view_balances');

    Route::get('wallets/{id}', [CurrencyWalletController::class, 'show'])
        ->middleware('identity.require-permission:multi_currency.view_balances');

    Route::post('wallets/{id}/transfers', [CurrencyWalletController::class, 'transfer'])
        ->middleware('identity.require-permission:multi_currency.transfer');

    // ── FX Fee Preview ───────────────────────────────────────────────────────
    Route::get('fx-fee', [FxFeeController::class, 'preview']);

    // ── FX Quotes ────────────────────────────────────────────────────────────
    Route::post('fx-quotes', [FxQuoteController::class, 'store'])
        ->middleware('identity.require-permission:multi_currency.convert');

    Route::post('fx-quotes/{id}/confirm', [FxQuoteController::class, 'confirm'])
        ->middleware('identity.require-permission:multi_currency.convert');

    // ── FX Transaction History ────────────────────────────────────────────────
    Route::get('fx-transactions', [FxTransactionController::class, 'index'])
        ->middleware('identity.require-permission:multi_currency.view_balances');

    Route::get('fx-transactions/{id}', [FxTransactionController::class, 'show'])
        ->middleware('identity.require-permission:multi_currency.view_balances');
});
