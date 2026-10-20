<?php

declare(strict_types=1);

use App\Modules\Admin\Infrastructure\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Infrastructure\Http\Controllers\AdminUserController;
use App\Modules\Admin\Infrastructure\Http\Controllers\AdminWalletController;
use App\Modules\Admin\Infrastructure\Http\Controllers\AdminTransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E19 Admin Portal Routes
|--------------------------------------------------------------------------
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Loaded by AdminServiceProvider::boot().
*/

Route::prefix('v1/admin')->middleware(['identity.auth'])->group(static function (): void {
    // Identity owns /users for administrator accounts. Customer portal reads use
    // an explicit resource path to avoid provider-order route collisions.
    Route::get('customers', [AdminUserController::class, 'list'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('customers/{userId}', [AdminUserController::class, 'show'])
        ->middleware('identity.require-permission:admin.read');

    // — Dashboard (US-ADMIN-001–008) ——————————————————————————————
    Route::get('dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/users', [AdminDashboardController::class, 'users'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/wallets', [AdminDashboardController::class, 'wallets'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/transactions', [AdminDashboardController::class, 'transactions'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/failures', [AdminDashboardController::class, 'failures'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/kyc', [AdminDashboardController::class, 'kyc'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/agents', [AdminDashboardController::class, 'agents'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('dashboard/risk', [AdminDashboardController::class, 'risk'])
        ->middleware('identity.require-permission:admin.read');

    // — User Management (US-ADMIN-009–014) ————————————————————————
    Route::get('users', [AdminUserController::class, 'list'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('users/{userId}', [AdminUserController::class, 'show'])
        ->middleware('identity.require-permission:admin.read');
    Route::post('users/{userId}/suspend', [AdminUserController::class, 'suspend'])
        ->middleware('identity.require-permission:admin.write');
    Route::post('users/{userId}/reactivate', [AdminUserController::class, 'reactivate'])
        ->middleware('identity.require-permission:admin.write');
    Route::post('users/{userId}/reset-auth', [AdminUserController::class, 'resetAuth'])
        ->middleware('identity.require-permission:admin.write');
    Route::get('users/{userId}/sessions', [AdminUserController::class, 'sessions'])
        ->middleware('identity.require-permission:admin.read');
    Route::delete('users/{userId}/sessions/{sessionId}', [AdminUserController::class, 'terminateSession'])
        ->middleware('identity.require-permission:admin.write');

    // — Wallet Management (US-ADMIN-015–021) —————————————————————
    Route::get('wallets', [AdminWalletController::class, 'list'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('wallets/{walletId}', [AdminWalletController::class, 'show'])
        ->middleware('identity.require-permission:admin.read');
    Route::post('wallets/{walletId}/freeze', [AdminWalletController::class, 'freeze'])
        ->middleware('identity.require-permission:admin.freeze_wallet');
    Route::post('wallets/{walletId}/unfreeze', [AdminWalletController::class, 'unfreeze'])
        ->middleware('identity.require-permission:admin.freeze_wallet');
    Route::get('wallets/{walletId}/ledger', [AdminWalletController::class, 'ledger'])
        ->middleware('identity.require-permission:admin.read');
    Route::post('wallets/{walletId}/adjustments', [AdminWalletController::class, 'createAdjustment'])
        ->middleware('identity.require-permission:admin.write');

    // — Adjustment approval (US-ADMIN-021) — note: no wallet ID in path
    Route::post('adjustments/{adjustmentId}/approve', [AdminWalletController::class, 'approveAdjustment'])
        ->middleware('identity.require-permission:admin.approve_adjustment');
    Route::post('adjustments/{adjustmentId}/reject', [AdminWalletController::class, 'rejectAdjustment'])
        ->middleware('identity.require-permission:admin.approve_adjustment');

    // — Transaction Management (US-ADMIN-022–027) ————————————————
    Route::get('transactions', [AdminTransactionController::class, 'list'])
        ->middleware('identity.require-permission:admin.read');
    Route::post('transactions/export', [AdminTransactionController::class, 'export'])
        ->middleware('identity.require-permission:admin.export_transactions');
    Route::get('transactions/exports/{jobId}', [AdminTransactionController::class, 'exportStatus'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('transactions/{transactionId}', [AdminTransactionController::class, 'show'])
        ->middleware('identity.require-permission:admin.read');
    Route::get('transactions/{transactionId}/investigate', [AdminTransactionController::class, 'investigate'])
        ->middleware('identity.require-permission:admin.read');
    Route::post('transactions/{transactionId}/reverse', [AdminTransactionController::class, 'reverse'])
        ->middleware('identity.require-permission:admin.reverse_transaction');
    Route::post('transactions/{transactionId}/refund', [AdminTransactionController::class, 'refund'])
        ->middleware('identity.require-permission:admin.refund_transaction');
});
