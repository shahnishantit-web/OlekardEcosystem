<?php

declare(strict_types=1);

use App\Modules\Transfer\Infrastructure\Http\Controllers\AdminTransferController;
use App\Modules\Transfer\Infrastructure\Http\Controllers\RecipientLookupController;
use App\Modules\Transfer\Infrastructure\Http\Controllers\TransferController;
use App\Modules\Transfer\Infrastructure\Http\Controllers\TransferReceiptController;
use Illuminate\Support\Facades\Route;

// Customer-facing transfer routes
Route::prefix('api/v1/transfers')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // US1: Recipient lookup + transfer initiation
        Route::post('/recipient-lookup', [RecipientLookupController::class, 'lookup'])
            ->middleware([
                'identity.require-permission:transfer.initiate',
                'throttle:transfer-lookup',
            ]);

        Route::post('/', [TransferController::class, 'initiate'])
            ->middleware(['identity.require-permission:transfer.initiate', 'idempotency:require']);

        // US2: Transfer confirmation
        Route::post('/{transferId}/confirm', [TransferController::class, 'confirm'])
            ->middleware(['identity.require-permission:transfer.initiate', 'idempotency:require']);

        // US3: Cancel transfer
        Route::post('/{transferId}/cancel', [TransferController::class, 'cancel'])
            ->middleware('identity.require-permission:transfer.initiate');

        // US4: History and receipts
        Route::get('/', [TransferController::class, 'index'])
            ->middleware('identity.require-permission:transfer.read');

        // Static prefix routes must be registered before dynamic /{transferId}
        Route::get('/receipts/{receiptReference}', [TransferReceiptController::class, 'showByReference'])
            ->middleware('identity.require-permission:transfer.read');

        Route::get('/{transferId}', [TransferController::class, 'show'])
            ->middleware('identity.require-permission:transfer.read');

        Route::get('/{transferId}/receipt', [TransferReceiptController::class, 'show'])
            ->middleware('identity.require-permission:transfer.read');
    });

// Admin transfer routes
Route::prefix('api/v1/admin/transfers')
    ->middleware(['identity.auth', 'identity.require-permission:transfer.admin'])
    ->group(function (): void {
        Route::get('/', [AdminTransferController::class, 'index']);
        Route::get('/{transferId}', [AdminTransferController::class, 'show']);
        Route::post('/{transferId}/force-fail', [AdminTransferController::class, 'forceFail']);
    });
