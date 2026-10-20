<?php

declare(strict_types=1);

use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ReconciliationBatchController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ReconciliationExceptionController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ReconciliationMatchingController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\SettlementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E20 Reconciliation & Settlement Routes
|--------------------------------------------------------------------------
| Auth: identity.auth (JWT required) on all routes except /batches/api.
| /batches/api uses reconciliation.webhook_auth (HMAC-SHA256).
| Permission: identity.require-permission:{slug}.
| Loaded by ReconciliationServiceProvider::boot().
*/

// Webhook endpoint — HMAC auth only, no JWT
Route::prefix('v1/reconciliation')->middleware(['reconciliation.webhook_auth'])->group(
    static function (): void {
        Route::post('batches/api', [ReconciliationBatchController::class, 'receiveApi']);
    }
);

Route::prefix('v1/reconciliation')->middleware(['identity.auth'])->group(static function (): void {

    // — Batch Management (US-RECON-001, 002, 008) ——————————————————
    Route::get('batches', [ReconciliationBatchController::class, 'index'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::get('batches/{batchId}', [ReconciliationBatchController::class, 'show'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::post('batches/file', [ReconciliationBatchController::class, 'importFile'])
        ->middleware('identity.require-permission:reconciliation.write');
    Route::post('batches/bank-statement', [ReconciliationBatchController::class, 'importBankStatement'])
        ->middleware('identity.require-permission:reconciliation.write');

    // — Matching & Items (US-RECON-003–005, 009–011) ———————————————
    Route::get('batches/{batchId}/items', [ReconciliationMatchingController::class, 'listItems'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::get('batches/{batchId}/unmatched', [ReconciliationMatchingController::class, 'listUnmatched'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::post('batches/{batchId}/match', [ReconciliationMatchingController::class, 'triggerMatch'])
        ->middleware('identity.require-permission:reconciliation.write');
    Route::post('items/{itemId}/mark-reconciled', [ReconciliationMatchingController::class, 'markReconciled'])
        ->middleware('identity.require-permission:reconciliation.write');

    // — Exceptions (US-RECON-006–007) ———————————————————————————
    Route::get('exceptions', [ReconciliationExceptionController::class, 'index'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::get('exceptions/{exceptionId}', [ReconciliationExceptionController::class, 'show'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::post('exceptions/{exceptionId}/resolve', [ReconciliationExceptionController::class, 'resolve'])
        ->middleware('identity.require-permission:reconciliation.write');

    // — Settlement (US-RECON-012–015) ————————————————————————————
    Route::get('settlements', [SettlementController::class, 'index'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::get('settlements/{settlementId}', [SettlementController::class, 'show'])
        ->middleware('identity.require-permission:reconciliation.read');
    Route::post('settlements', [SettlementController::class, 'create'])
        ->middleware('identity.require-permission:reconciliation.write');
    Route::post('settlements/{settlementId}/submit', [SettlementController::class, 'submit'])
        ->middleware('identity.require-permission:reconciliation.write');
    Route::post('settlements/{settlementId}/approve', [SettlementController::class, 'approve'])
        ->middleware('identity.require-permission:reconciliation.approve_settlement');
    Route::post('settlements/{settlementId}/reject', [SettlementController::class, 'reject'])
        ->middleware('identity.require-permission:reconciliation.approve_settlement');
    Route::post('settlements/{settlementId}/execute', [SettlementController::class, 'execute'])
        ->middleware('identity.require-permission:reconciliation.execute_settlement');
});
