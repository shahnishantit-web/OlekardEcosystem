<?php

declare(strict_types=1);

use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatCashInController;
use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatCashOutController;
use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatFundingController;
use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatLimitController;
use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatTransactionController;
use App\Modules\AgentFloat\Infrastructure\Http\Controllers\AgentFloatWalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E13 Agent Float Management Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/.
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Routes are loaded by AgentFloatServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Float Wallet Provisioning & View (US1, US3) ─────────────────────────

    // T019: US1 — Create float wallet for agent (admin only, idempotent)
    Route::post('agents/{agentId}/float-wallet', [AgentFloatWalletController::class, 'create'])
        ->middleware(['identity.require-permission:agent_float.wallet.create', 'idempotency:require']);

    // T029: US3 — View float wallet balance and status
    Route::get('agents/{agentId}/float-wallet', [AgentFloatWalletController::class, 'show'])
        ->middleware('identity.require-permission:agent_float.wallet.view');

    // ── Float Funding (US2) ──────────────────────────────────────────────────

    // T026: US2 — Admin funds agent float wallet (idempotent)
    Route::post('agents/{agentId}/float-wallet/fund', [AgentFloatFundingController::class, 'fund'])
        ->middleware(['identity.require-permission:agent_float.wallet.fund', 'idempotency:require']);

    // ── Float Limits (US7) ───────────────────────────────────────────────────

    // T053: US7 — Update float limits (admin only)
    Route::patch('agents/{agentId}/float-wallet/limits', [AgentFloatLimitController::class, 'update'])
        ->middleware('identity.require-permission:agent_float.limits.manage');

    // T053: US7 — View float limits
    Route::get('agents/{agentId}/float-wallet/limits', [AgentFloatLimitController::class, 'show'])
        ->middleware('identity.require-permission:agent_float.limits.view');

    // ── Float Transaction History (US9) ─────────────────────────────────────

    // T065: US9 — View float transaction history
    Route::get('agents/{agentId}/float-wallet/transactions', [AgentFloatTransactionController::class, 'index'])
        ->middleware('identity.require-permission:agent_float.transactions.view');

    // ── Cash-In / Cash-Out (US4, US5) ───────────────────────────────────────

    // T035: US4 — Agent performs cash-in for linked customer (idempotent)
    Route::post('agents/{agentId}/customers/{customerId}/cash-in', [AgentFloatCashInController::class, 'cashIn'])
        ->middleware(['identity.require-permission:agent_float.cash_in', 'idempotency:require']);

    // T043: US5 — Agent performs cash-out for linked customer (idempotent)
    Route::post('agents/{agentId}/customers/{customerId}/cash-out', [AgentFloatCashOutController::class, 'cashOut'])
        ->middleware(['identity.require-permission:agent_float.cash_out', 'idempotency:require']);
});
