<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Http\Controllers\DeadLetterAdminController;
use App\Modules\Platform\Infrastructure\Http\Controllers\LedgerIntegrityAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E27 Reliability & Transaction Integrity Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/.
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:operations.dead_letter.manage
| Routes are loaded by PlatformServiceProvider::boot().
*/

Route::prefix('v1/operations')->middleware(['identity.auth', 'identity.require-permission:operations.dead_letter.manage'])->group(static function (): void {

    // ── Dead-Letter Items (US4) ──────────────────────────────────────────────
    // Routes completed by T048

    Route::get('dead-letter', [DeadLetterAdminController::class, 'index']);
    Route::get('dead-letter/stats', [DeadLetterAdminController::class, 'stats']);
    Route::get('dead-letter/{id}', [DeadLetterAdminController::class, 'show']);
    Route::post('dead-letter/{id}/replay', [DeadLetterAdminController::class, 'replay']);
    Route::post('dead-letter/{id}/dismiss', [DeadLetterAdminController::class, 'dismiss']);

    // ── Ledger Integrity Snapshots (US2) ─────────────────────────────────────
    // Route added by T025

    Route::get('ledger-integrity/snapshots', [LedgerIntegrityAdminController::class, 'index']);
});
