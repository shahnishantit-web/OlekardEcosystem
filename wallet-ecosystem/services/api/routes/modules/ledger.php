<?php

declare(strict_types=1);

use App\Modules\Ledger\Infrastructure\Http\Controllers\LedgerAccountController;
use App\Modules\Ledger\Infrastructure\Http\Controllers\LedgerEntryController;
use App\Modules\Ledger\Infrastructure\Http\Controllers\LedgerJournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/ledger')
    ->middleware(['identity.auth'])
    ->group(function (): void {

        // US1+US2: Post debit/credit journal entries
        Route::post('/entries/debit',   [LedgerEntryController::class, 'debit'])
            ->middleware('identity.require-permission:ledger.post');
        Route::post('/entries/credit',  [LedgerEntryController::class, 'credit'])
            ->middleware('identity.require-permission:ledger.post');

        // US3: Post fee journal entry
        Route::post('/entries/fee',     [LedgerEntryController::class, 'fee'])
            ->middleware('identity.require-permission:ledger.post');

        // US4: Post revenue journal entry
        Route::post('/entries/revenue', [LedgerEntryController::class, 'revenue'])
            ->middleware('identity.require-permission:ledger.post');

        // US5: Post commission journal entry
        Route::post('/entries/commission', [LedgerEntryController::class, 'commission'])
            ->middleware('identity.require-permission:ledger.post');

        // US9: Reverse a posted journal entry
        Route::post('/entries/{id}/reverse', [LedgerEntryController::class, 'reverse'])
            ->middleware('identity.require-permission:ledger.entry.reverse');

        // US12: Get balance for a ledger account
        Route::get('/accounts/{reference}/balance', [LedgerAccountController::class, 'balance'])
            ->middleware('identity.require-permission:ledger.read');

        // US10: Get paginated account entry history
        Route::get('/accounts/{reference}/entries', [LedgerAccountController::class, 'entries'])
            ->middleware('identity.require-permission:ledger.read');

        // US11: Retrieve specific journal with lines
        Route::get('/journals/{id}',  [LedgerJournalController::class, 'show'])
            ->middleware('identity.require-permission:ledger.read');

        // US11: Query journals by transaction_reference
        Route::get('/journals',       [LedgerJournalController::class, 'index'])
            ->middleware('identity.require-permission:ledger.read');

        // US6/US14: Explicit account registration
        Route::post('/accounts',      [LedgerAccountController::class, 'register'])
            ->middleware('identity.require-permission:ledger.account.register');
    });
