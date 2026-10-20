<?php

declare(strict_types=1);

use App\Modules\Risk\Infrastructure\Http\Controllers\RiskAlertController;
use App\Modules\Risk\Infrastructure\Http\Controllers\RiskEvaluationController;
use App\Modules\Risk\Infrastructure\Http\Controllers\RiskRuleController;
use App\Modules\Risk\Infrastructure\Http\Controllers\SarController;
use App\Modules\Risk\Infrastructure\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E18 Fraud, Risk & AML Routes
|--------------------------------------------------------------------------
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Routes loaded by RiskServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Risk Evaluation (US-RISK-001 through US-RISK-009) ──────────────────

    Route::post('risk/evaluate-transaction-risk', [RiskEvaluationController::class, 'evaluateTransactionRisk'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/evaluate-transaction-velocity', [RiskEvaluationController::class, 'evaluateTransactionVelocity'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/evaluate-amount-threshold', [RiskEvaluationController::class, 'evaluateAmountThreshold'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/evaluate-device-risk', [RiskEvaluationController::class, 'evaluateDeviceRisk'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/evaluate-ip-risk', [RiskEvaluationController::class, 'evaluateIpRisk'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/evaluate-beneficiary-risk', [RiskEvaluationController::class, 'evaluateBeneficiaryRisk'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/allow-transaction', [RiskEvaluationController::class, 'allowTransaction'])
        ->middleware('identity.require-permission:risk.manage_alerts');

    Route::post('risk/block-transaction', [RiskEvaluationController::class, 'blockTransaction'])
        ->middleware('identity.require-permission:risk.manage_alerts');

    Route::post('risk/send-transaction-for-manual-review', [RiskEvaluationController::class, 'sendTransactionForManualReview'])
        ->middleware('identity.require-permission:risk.evaluate');

    // ── Async Detection (US-RISK-016 through US-RISK-018) ──────────────────

    Route::post('risk/detect-unusual-transaction-pattern', [RiskEvaluationController::class, 'detectUnusualTransactionPattern'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/detect-rapid-fund-movement', [RiskEvaluationController::class, 'detectRapidFundMovement'])
        ->middleware('identity.require-permission:risk.evaluate');

    Route::post('risk/detect-repeated-failed-authentication', [RiskEvaluationController::class, 'detectRepeatedFailedAuthentication'])
        ->middleware('identity.require-permission:risk.evaluate');

    // ── Risk Rule Configuration (US-RISK-010 through US-RISK-015) ──────────

    Route::get('risk/rules', [RiskRuleController::class, 'listRules'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-per-transaction-limit', [RiskRuleController::class, 'configurePerTransactionLimit'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-daily-limit', [RiskRuleController::class, 'configureDailyLimit'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-monthly-limit', [RiskRuleController::class, 'configureMonthlyLimit'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-customer-limits', [RiskRuleController::class, 'configureCustomerLimits'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-agent-limits', [RiskRuleController::class, 'configureAgentLimits'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::post('risk/configure-wallet-limits', [RiskRuleController::class, 'configureWalletLimits'])
        ->middleware('identity.require-permission:risk.configure_rules');

    // ── Risk Alerts (US-RISK-019 through US-RISK-021) ──────────────────────

    Route::post('risk/alerts', [RiskAlertController::class, 'createRiskAlert'])
        ->middleware('identity.require-permission:risk.manage_alerts');

    Route::get('risk/alerts', [RiskAlertController::class, 'listAlerts'])
        ->middleware('identity.require-permission:risk.view_alerts');

    Route::get('risk/alerts/{id}', [RiskAlertController::class, 'getAlert'])
        ->middleware('identity.require-permission:risk.view_alerts');

    Route::post('risk/investigate-risk-alert', [RiskAlertController::class, 'investigateRiskAlert'])
        ->middleware('identity.require-permission:risk.manage_alerts');

    Route::post('risk/resolve-risk-alert', [RiskAlertController::class, 'resolveRiskAlert'])
        ->middleware('identity.require-permission:risk.manage_alerts');

    // ── Account Freeze (US-RISK-022) ────────────────────────────────────────

    Route::post('risk/freeze-account-due-to-risk', [RiskAlertController::class, 'freezeAccountDueToRisk'])
        ->middleware('identity.require-permission:risk.freeze_account');

    Route::post('risk/lift-account-hold', [RiskAlertController::class, 'liftAccountHold'])
        ->middleware('identity.require-permission:risk.freeze_account');

    // ── SAR Workflow (US-RISK-021 cont.) ────────────────────────────────────

    Route::get('risk/sars', [SarController::class, 'list'])
        ->middleware('identity.require-permission:risk.file_sar');

    Route::get('risk/sars/{id}', [SarController::class, 'show'])
        ->middleware('identity.require-permission:risk.file_sar');

    Route::post('risk/sars/{id}/approve', [SarController::class, 'approve'])
        ->middleware('identity.require-permission:risk.file_sar');

    Route::post('risk/sars/{id}/submit', [SarController::class, 'submit'])
        ->middleware('identity.require-permission:risk.file_sar');

    // ── Watchlist Management ────────────────────────────────────────────────

    Route::post('risk/watchlist', [WatchlistController::class, 'store'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::put('risk/watchlist/{id}', [WatchlistController::class, 'update'])
        ->middleware('identity.require-permission:risk.configure_rules');

    Route::delete('risk/watchlist/{id}', [WatchlistController::class, 'deactivate'])
        ->middleware('identity.require-permission:risk.configure_rules');
});
