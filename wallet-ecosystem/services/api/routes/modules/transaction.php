<?php

declare(strict_types=1);

use App\Modules\Transaction\Infrastructure\Http\Controllers\ReceiptController;
use App\Modules\Transaction\Infrastructure\Http\Controllers\RefundController;
use App\Modules\Transaction\Infrastructure\Http\Controllers\ReversalController;
use App\Modules\Transaction\Infrastructure\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/transactions')->middleware(['identity.auth'])->group(function (): void {

    Route::get('/', [TransactionController::class, 'index'])
        ->middleware('identity.require-permission:transaction.read');

    Route::get('/{id}', [TransactionController::class, 'show'])
        ->middleware('identity.require-permission:transaction.read');

    Route::get('/{id}/status-history', [TransactionController::class, 'statusHistory'])
        ->middleware('identity.require-permission:transaction.read');

    Route::get('/{id}/receipt', [ReceiptController::class, 'show'])
        ->middleware('identity.require-permission:transaction.receipt.read');

    Route::post('/{id}/receipt/share', [ReceiptController::class, 'share'])
        ->middleware('identity.require-permission:transaction.receipt.share');

    Route::post('/{id}/reversals', [ReversalController::class, 'request'])
        ->middleware('identity.require-permission:transaction.reversal.request');

    Route::get('/{id}/reversals', [ReversalController::class, 'index'])
        ->middleware('identity.require-permission:transaction.reversal.request');

    Route::post('/{id}/reversals/{rid}/approve', [ReversalController::class, 'approve'])
        ->middleware('identity.require-permission:transaction.reversal.approve');

    Route::post('/{id}/reversals/{rid}/reject', [ReversalController::class, 'reject'])
        ->middleware('identity.require-permission:transaction.reversal.reject');

    Route::post('/{id}/reversals/{rid}/execute', [ReversalController::class, 'execute'])
        ->middleware('identity.require-permission:transaction.reversal.execute');

    Route::get('/{id}/reversals/{rid}/audit', [ReversalController::class, 'audit'])
        ->middleware('identity.require-permission:transaction.reversal.audit');

    Route::post('/{id}/refunds', [RefundController::class, 'initiate'])
        ->middleware('identity.require-permission:transaction.refund.initiate');
});
