<?php

declare(strict_types=1);

use App\Modules\Payment\Http\Controllers\Admin\PaymentProviderAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ReconciliationExceptionAdminController;
use App\Modules\Payment\Http\Controllers\PaymentAttemptController;
use App\Modules\Payment\Http\Controllers\PaymentCallbackController;
use App\Modules\Payment\Http\Controllers\PaymentIntentController;
use App\Modules\Payment\Http\Controllers\PaymentOptionsController;
use App\Modules\Payment\Http\Controllers\PaymentRefundController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment Module Routes — E48 Shared Payment Integration
|--------------------------------------------------------------------------
*/

// Consumer-facing payment routes (Phase 5)
Route::prefix('api/v1/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // Payment options discovery
        Route::get('/options', [PaymentOptionsController::class, 'index'])
            ->middleware('identity.require-permission:payment.intents.read');

        // Payment intent lifecycle
        Route::post('/intents', [PaymentIntentController::class, 'store'])
            ->middleware([
                'identity.require-permission:payment.intents.manage',
                'idempotency:require',
                'throttle:payment-intent',
            ]);

        Route::get('/intents/{id}/status', [PaymentIntentController::class, 'status'])
            ->middleware('identity.require-permission:payment.intents.read');

        // Payment attempt initiation
        Route::post('/intents/{intentId}/attempts', [PaymentAttemptController::class, 'store'])
            ->middleware([
                'identity.require-permission:payment.intents.manage',
                'idempotency:require',
            ]);

        // Refund request
        Route::post('/intents/{intentId}/refunds', [PaymentRefundController::class, 'store'])
            ->middleware([
                'identity.require-permission:payment.refunds.request',
                'idempotency:require',
            ]);
    });

// T063: Admin payment provider and reconciliation routes
Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth', 'identity.require-permission:payment.providers.read'])
    ->group(function (): void {
        // Provider management
        Route::get('/providers', [PaymentProviderAdminController::class, 'index']);
        Route::get('/providers/{id}', [PaymentProviderAdminController::class, 'show']);

        Route::post('/providers', [PaymentProviderAdminController::class, 'store'])
            ->middleware('identity.require-permission:payment.providers.manage');

        Route::put('/providers/{id}', [PaymentProviderAdminController::class, 'update'])
            ->middleware('identity.require-permission:payment.providers.manage');

        Route::post('/providers/{id}/suspend', [PaymentProviderAdminController::class, 'suspend'])
            ->middleware('identity.require-permission:payment.providers.manage');

        Route::post('/providers/{providerId}/accounts/{accountId}/rotate-credential', [PaymentProviderAdminController::class, 'rotateCredential'])
            ->middleware('identity.require-permission:payment.config.approve_high_risk');

        // Reconciliation exceptions
        Route::get('/reconciliation/exceptions', [ReconciliationExceptionAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.reconciliation.view');

        Route::patch('/reconciliation/exceptions/{id}', [ReconciliationExceptionAdminController::class, 'update'])
            ->middleware('identity.require-permission:payment.reconciliation.manage');
    });

// T129: Provider callback receiver — NO auth (called by external providers).
// Rate-limited to protect against DDoS. Raw body persisted before async processing.
Route::prefix('api/v1/payment')
    ->middleware(['throttle:payment-callback'])
    ->group(function (): void {
        Route::post('/callbacks/{providerCode}', [PaymentCallbackController::class, 'store']);
    });
