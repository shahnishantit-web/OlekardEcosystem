<?php

declare(strict_types=1);

use App\Modules\Commission\Infrastructure\Http\Controllers\AgentCommissionController;
use App\Modules\Commission\Infrastructure\Http\Controllers\AgentCommissionTierController;
use App\Modules\Commission\Infrastructure\Http\Controllers\CommissionPayoutController;
use App\Modules\Commission\Infrastructure\Http\Controllers\CommissionReportController;
use App\Modules\Commission\Infrastructure\Http\Controllers\CommissionReversalController;
use App\Modules\Commission\Infrastructure\Http\Controllers\CommissionRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E14 Agent Commission Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/.
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Routes are loaded by CommissionServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Commission Rules (US1) ───────────────────────────────────────────────

    Route::get('commission-rules', [CommissionRuleController::class, 'index'])
        ->middleware('identity.require-permission:commission.rules.view');

    Route::post('commission-rules', [CommissionRuleController::class, 'store'])
        ->middleware('identity.require-permission:commission.rules.create');

    Route::get('commission-rules/{ruleId}', [CommissionRuleController::class, 'show'])
        ->middleware('identity.require-permission:commission.rules.view');

    Route::patch('commission-rules/{ruleId}', [CommissionRuleController::class, 'update'])
        ->middleware('identity.require-permission:commission.rules.update');

    Route::delete('commission-rules/{ruleId}', [CommissionRuleController::class, 'destroy'])
        ->middleware('identity.require-permission:commission.rules.delete');

    // ── Agent Commission Views (US5, US6) ────────────────────────────────────

    Route::get('agents/{agentId}/commissions', [AgentCommissionController::class, 'index']);

    Route::get('agents/{agentId}/commissions/pending', [AgentCommissionController::class, 'pending']);

    // ── Commission Tier Management (US3) ─────────────────────────────────────

    Route::patch('agents/{agentId}/commission-tier', [AgentCommissionTierController::class, 'update'])
        ->middleware('identity.require-permission:commission.tier.manage');

    // ── Commission Reversal (FR-E14-014) ─────────────────────────────────────

    Route::post('commissions/{commissionId}/reverse', [CommissionReversalController::class, 'reverse'])
        ->middleware(['identity.require-permission:commission.reverse', 'idempotency:require']);

    // ── Commission Payouts (US7, US8) ────────────────────────────────────────

    Route::post('commission-payouts', [CommissionPayoutController::class, 'store'])
        ->middleware('identity.require-permission:commission.payout.approve');

    Route::get('commission-payouts/{payoutId}', [CommissionPayoutController::class, 'show'])
        ->middleware('identity.require-permission:commission.payout.approve');

    Route::delete('commission-payouts/{payoutId}', [CommissionPayoutController::class, 'cancel'])
        ->middleware('identity.require-permission:commission.payout.cancel');

    // ── Commission Report (US9) ──────────────────────────────────────────────

    Route::get('commissions/report', [CommissionReportController::class, 'index'])
        ->middleware('identity.require-permission:commission.report.view');
});
