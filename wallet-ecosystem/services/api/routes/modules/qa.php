<?php

declare(strict_types=1);

use App\Modules\AdminApp\Infrastructure\Http\Middleware\AddCorrelationIdHeader;
use App\Modules\Qa\Http\Controllers\QaDashboardController;
use App\Modules\Qa\Http\Controllers\QaRunController;
use App\Modules\Qa\Http\Controllers\QaTriggerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/qa')
    ->middleware(['identity.auth', AddCorrelationIdHeader::class])
    ->group(function (): void {

        Route::middleware(['identity.require-permission:qa.execute'])
            ->group(function (): void {

                // Single parameterized trigger route; slug validation in TriggerQaRunRequest
                Route::post('/{category}', [QaTriggerController::class, 'trigger'])
                    ->name('qa.trigger')
                    ->whereIn('category', [
                        'unit-test-authentication',
                        'integration-test-authentication',
                        'api-authorization-testing',
                        'wallet-balance-testing',
                        'ledger-balance-testing',
                        'transfer-testing',
                        'duplicate-transaction-testing',
                        'callback-testing',
                        'payment-failure-testing',
                        'reconciliation-testing',
                        'kyc-testing',
                        'agent-testing',
                        'security-testing',
                        'performance-testing',
                        'load-testing',
                        'disaster-recovery-testing',
                        'uat',
                        'regression-testing',
                    ]);

                // Run listing and detail — must come after trigger to avoid conflict
                Route::get('/runs', [QaRunController::class, 'index'])->name('qa.runs.index');
                Route::get('/runs/{id}', [QaRunController::class, 'show'])->name('qa.runs.show');

                // Dashboard aggregate
                Route::get('/dashboard', [QaDashboardController::class, 'index'])->name('qa.dashboard');
            });
    });
