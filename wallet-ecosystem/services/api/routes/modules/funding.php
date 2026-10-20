<?php

declare(strict_types=1);

use App\Modules\Funding\Infrastructure\Http\Controllers\AdminUnmatchedDepositController;
use App\Modules\Funding\Infrastructure\Http\Controllers\AgentTransactionController;
use App\Modules\Funding\Infrastructure\Http\Controllers\BankNotificationController;
use App\Modules\Funding\Infrastructure\Http\Controllers\FundingReceiptController;
use App\Modules\Funding\Infrastructure\Http\Controllers\FundingTransactionController;
use App\Modules\Funding\Infrastructure\Http\Controllers\ProviderCallbackController;
use Illuminate\Support\Facades\Route;

if (app()->environment(['local', 'testing'])) {
    Route::post('api/v1/dev/mock-bank/funding/{id}/complete',
        [\App\Modules\Funding\Infrastructure\Http\Controllers\DevMockBankController::class, 'complete'])
        ->middleware(['identity.auth', 'identity.require-permission:funding.initiate', 'idempotency:require']);
}

// ─── Authenticated customer & agent endpoints ──────────────────────────────────
Route::prefix('api/v1/funding')
    ->middleware(['identity.auth'])
    ->group(function (): void {

        // Funding transaction lifecycle (FR-E08-001 — FR-E08-035)
        Route::post('/transactions', [FundingTransactionController::class, 'initiate'])
            ->middleware(['identity.require-permission:funding.initiate', 'idempotency:require']);

        Route::get('/transactions', [FundingTransactionController::class, 'index'])
            ->middleware('identity.require-permission:funding.read');

        Route::get('/transactions/{id}', [FundingTransactionController::class, 'show'])
            ->middleware('identity.require-permission:funding.read');

        Route::post('/transactions/{id}/confirm', [FundingTransactionController::class, 'confirm'])
            ->middleware('identity.require-permission:funding.initiate');

        Route::post('/transactions/{id}/cancel', [FundingTransactionController::class, 'cancel'])
            ->middleware('identity.require-permission:funding.initiate');

        // Receipt — by transaction ID (convenience) and by receipt_reference (FR-E08-043 canonical)
        Route::get('/transactions/{transactionId}/receipt', [FundingReceiptController::class, 'show'])
            ->middleware('identity.require-permission:funding.read');

        Route::get('/receipts/{receiptReference}', [FundingReceiptController::class, 'showByReference'])
            ->middleware('identity.require-permission:funding.read');

        // Agent cash-in (FR-E08-036 — FR-E08-044)
        Route::post('/agent-transactions', [AgentTransactionController::class, 'cashIn'])
            ->middleware(['identity.require-permission:funding.agent-cashin', 'idempotency:require']);

        Route::get('/agent-transactions', [AgentTransactionController::class, 'index'])
            ->middleware('identity.require-permission:funding.agent-cashin');
    });

// ─── Admin endpoints ──────────────────────────────────────────────────────────
Route::prefix('api/v1/admin/funding')
    ->middleware(['identity.auth', 'identity.require-permission:funding.unmatched.review'])
    ->group(function (): void {
        Route::get('/unmatched-deposits', [AdminUnmatchedDepositController::class, 'index']);
        Route::patch('/unmatched-deposits/{id}', [AdminUnmatchedDepositController::class, 'resolve']);
    });

// ─── Provider callbacks (public — HMAC-verified inline) ───────────────────────
Route::prefix('api/v1/funding')
    ->group(function (): void {
        Route::post('/callbacks/{provider}', [ProviderCallbackController::class, 'handle']);
    });

// ─── Bank notifications (system-to-system — API key auth) ─────────────────────
Route::prefix('api/v1/funding')
    ->middleware(['funding.bank-api-key'])
    ->group(function (): void {
        Route::post('/bank/notifications', [BankNotificationController::class, 'handle']);
    });
