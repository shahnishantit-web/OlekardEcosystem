<?php

declare(strict_types=1);

use App\Http\Middleware\IdempotencyMiddleware;
use App\Modules\Fees\Infrastructure\Http\Controllers\FeeCalculateController;
use App\Modules\Fees\Infrastructure\Http\Controllers\FeeRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E21 Fees & Pricing Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/.
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Routes are loaded by FeesServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Fee Rules CRUD (US-FEE-001, US-FEE-002, US-FEE-003) ─────────────────

    Route::get('fees/rules', [FeeRuleController::class, 'index'])
        ->middleware('identity.require-permission:fees.rules.view');

    Route::post('fees/rules', [FeeRuleController::class, 'store'])
        ->middleware(['identity.require-permission:fees.rules.create', IdempotencyMiddleware::class]);

    Route::get('fees/rules/{ruleId}', [FeeRuleController::class, 'show'])
        ->middleware('identity.require-permission:fees.rules.view');

    Route::patch('fees/rules/{ruleId}', [FeeRuleController::class, 'update'])
        ->middleware(['identity.require-permission:fees.rules.update', IdempotencyMiddleware::class]);

    Route::delete('fees/rules/{ruleId}', [FeeRuleController::class, 'destroy'])
        ->middleware('identity.require-permission:fees.rules.delete');

    // ── Fee Preview (US-FEE-008) ─────────────────────────────────────────────

    Route::post('fees/calculate', [FeeCalculateController::class, 'calculate'])
        ->middleware('identity.require-permission:fees.calculate');
});
