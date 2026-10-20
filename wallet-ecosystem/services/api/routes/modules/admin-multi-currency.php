<?php

declare(strict_types=1);

use App\Modules\MultiCurrency\Http\Controllers\Admin\AdminCurrencyController;
use App\Modules\MultiCurrency\Http\Controllers\Admin\AdminFxManagementController;
use App\Modules\MultiCurrency\Http\Controllers\Admin\AdminFxRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E29 Multi-Currency Wallet — Admin Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
*/

Route::prefix('v1/admin/multi-currency')->middleware(['identity.auth'])->group(static function (): void {

    // ── Currency Management ──────────────────────────────────────────────────
    Route::get('currencies', [AdminFxManagementController::class, 'currencies'])
        ->middleware('identity.require-permission:multi_currency.manage_currencies');

    Route::post('currencies', [AdminCurrencyController::class, 'store'])
        ->middleware('identity.require-permission:multi_currency.manage_currencies');

    Route::patch('currencies/{code}', [AdminFxManagementController::class, 'updateCurrency'])
        ->middleware('identity.require-permission:multi_currency.manage_currencies');

    // ── FX Rate Management ────────────────────────────────────────────────────
    Route::get('fx-rates', [AdminFxRateController::class, 'index'])
        ->middleware('identity.require-permission:multi_currency.configure_rate');

    Route::post('fx-rates', [AdminFxRateController::class, 'store'])
        ->middleware('identity.require-permission:multi_currency.configure_rate');

    Route::post('fx-rates/{id}/activate', [AdminFxRateController::class, 'activate'])
        ->middleware(['identity.require-permission:multi_currency.configure_rate', 'identity.require-permission:configuration.approve']);
});

Route::prefix('v1/admin')->middleware(['identity.auth'])->group(static function (): void {
    Route::get('currencies', [AdminFxManagementController::class, 'currencies'])->middleware('identity.require-permission:multi_currency.manage_currencies');
    Route::patch('currencies/{code}', [AdminFxManagementController::class, 'updateCurrency'])->middleware('identity.require-permission:multi_currency.manage_currencies');
    Route::post('currencies/{code}/activate/{request}', [AdminFxManagementController::class, 'activateCurrency'])->middleware(['identity.require-permission:multi_currency.manage_currencies', 'identity.require-permission:configuration.approve']);
    Route::get('fx/rates', [AdminFxManagementController::class, 'rates'])->middleware('identity.require-permission:multi_currency.configure_rate');
    Route::post('fx/rates', [AdminFxManagementController::class, 'storeRate'])->middleware('identity.require-permission:multi_currency.configure_rate');
    Route::post('fx/rates/{id}/activate', [AdminFxManagementController::class, 'activateRate'])->middleware(['identity.require-permission:multi_currency.configure_rate', 'identity.require-permission:configuration.approve']);
    Route::get('fx/rates/{pair}/history', [AdminFxManagementController::class, 'history'])->middleware('identity.require-permission:multi_currency.configure_rate');
});
