<?php

declare(strict_types=1);

use App\Modules\Payment\Http\Controllers\Admin\ApiOperationConfigAdminController;
use App\Modules\Payment\Http\Controllers\Admin\BankAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentModuleConfigAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ProviderAccountAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ProviderConfigDraftListAdminController;
use App\Modules\Payment\Http\Controllers\Admin\BankLifecycleAdminController;
use App\Modules\Payment\Http\Controllers\BankStatementCallbackController;
use App\Modules\Payment\Http\Controllers\Admin\CertificationEvidenceAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ClearingRecordAdminController;
use App\Modules\Payment\Http\Controllers\Admin\CredentialRotationAdminController;
use App\Modules\Payment\Http\Controllers\Admin\IntegrationAccountAdminController;
use App\Modules\Payment\Http\Controllers\Admin\IntegrationProfileAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentConfigChangeDraftAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentProviderAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PendingAccountingController;
use App\Modules\Payment\Http\Controllers\Admin\ProviderStatementAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ReconciliationCorrectionAdminController;
use App\Modules\Payment\Http\Controllers\Admin\ReconciliationExceptionAdminController;
use App\Modules\Payment\Http\Controllers\Admin\RefundApprovalAdminController;
use App\Modules\Payment\Http\Controllers\PaymentAttemptController;
use App\Modules\Payment\Http\Controllers\PaymentCallbackController;
use App\Modules\Payment\Http\Controllers\PaymentIntentController;
use App\Modules\Payment\Http\Controllers\PaymentOptionsController;
use App\Modules\Payment\Http\Controllers\PaymentQuoteController;
use App\Modules\Payment\Http\Controllers\Customer\CustomerRefundStatusController;
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

        Route::get('/intents/{id}/quote', [PaymentQuoteController::class, 'show'])
            ->middleware('identity.require-permission:payment.intents.read');

        // Payment attempt initiation and status query (P06 T025/T027)
        Route::get('/intents/{intentId}/attempts/{attemptId}', [PaymentAttemptController::class, 'show'])
            ->middleware('identity.require-permission:payment.intents.read');

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

        // T032: P15 US6 — Consumer refund status (read-only; ownership enforced in controller)
        Route::get('/intents/{intentId}/refunds', [CustomerRefundStatusController::class, 'index'])
            ->middleware('identity.require-permission:payment.refunds.view');
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
            ->middleware([
                'identity.require-permission:payment.providers.manage',
                'identity.require-permission:payment.config.draft',
            ]);

        Route::post('/providers/{id}/suspend', [PaymentProviderAdminController::class, 'suspend'])
            ->middleware('identity.require-permission:payment.providers.manage');

        Route::post('/providers/{providerId}/accounts/{accountId}/rotate-credential', [PaymentProviderAdminController::class, 'rotateCredential'])
            ->middleware('identity.require-permission:payment.config.approve_high_risk');

        // P05: Adapter allowlist + eligibility preview (admin only)
        Route::get('/providers/adapters', [PaymentProviderAdminController::class, 'listAdapters'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.admin');

        Route::get('/providers/eligibility-preview', [PaymentProviderAdminController::class, 'eligibilityPreview'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.admin');

        // Config change drafts — US3 maker-checker (FR-04)
        Route::post('/providers/{id}/config-changes', [PaymentConfigChangeDraftAdminController::class, 'store'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware(['identity.require-permission:payment.config.draft', 'idempotency:require']);

        Route::post('/providers/{id}/config-changes/{draftId}/approve', [PaymentConfigChangeDraftAdminController::class, 'approve'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.config.approve_high_risk');

        Route::post('/providers/{id}/config-changes/{draftId}/reject', [PaymentConfigChangeDraftAdminController::class, 'reject'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.config.approve_high_risk');

        // Refund approval gate — US4 (FR-05, FR-09)
        Route::get('/refunds', [RefundApprovalAdminController::class, 'index'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.refunds.approve');

        Route::post('/refunds/{id}/approve', [RefundApprovalAdminController::class, 'approve'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware(['identity.require-permission:payment.refunds.approve', 'idempotency:require']);

        Route::post('/refunds/{id}/reject', [RefundApprovalAdminController::class, 'reject'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.refunds.approve');

        // Reconciliation exceptions
        Route::get('/reconciliation/exceptions', [ReconciliationExceptionAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.reconciliation.view');

        Route::patch('/reconciliation/exceptions/{id}', [ReconciliationExceptionAdminController::class, 'update'])
            ->middleware('identity.require-permission:payment.reconciliation.manage');

        // P08 US4: Pending accounting visibility
        Route::get('/intents/pending-accounting', [PendingAccountingController::class, 'index'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.intents.admin');

        // P20: Provider account management
        Route::get('/providers/{providerId}/accounts', [ProviderAccountAdminController::class, 'index']);
        Route::get('/providers/{providerId}/accounts/{accountId}', [ProviderAccountAdminController::class, 'show']);
        Route::patch('/providers/{providerId}/accounts/{accountId}', [ProviderAccountAdminController::class, 'update'])
            ->middleware('identity.require-permission:payment.providers.manage');
        Route::post('/providers/{providerId}/accounts/{accountId}/lifecycle', [ProviderAccountAdminController::class, 'lifecycle'])
            ->middleware('identity.require-permission:payment.providers.manage');

        // P20: Provider config draft list (read-only, scoped to provider)
        Route::get('/providers/{providerId}/config-changes', [ProviderConfigDraftListAdminController::class, 'index']);
        Route::get('/providers/{providerId}/config-changes/{draftId}', [ProviderConfigDraftListAdminController::class, 'show']);

        // P20: Module availability config
        Route::get('/config/modules', [PaymentModuleConfigAdminController::class, 'index']);
        Route::patch('/config/modules', [PaymentModuleConfigAdminController::class, 'update'])
            ->withoutMiddleware('identity.require-permission:payment.providers.read')
            ->middleware('identity.require-permission:payment.providers.manage');
    });

// T036: P15 US7 — Admin refund monitoring and management
Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::get('/refund-management', [\App\Modules\Payment\Http\Controllers\Admin\AdminRefundListController::class, 'index'])
            ->middleware('identity.require-permission:payment.refunds.view');

        Route::get('/refund-management/{id}', [\App\Modules\Payment\Http\Controllers\Admin\AdminRefundListController::class, 'show'])
            ->middleware('identity.require-permission:payment.refunds.view');

        Route::post('/refund-management/{id}/query-status', [\App\Modules\Payment\Http\Controllers\Admin\AdminRefundListController::class, 'queryStatus'])
            ->middleware('identity.require-permission:payment.refunds.manage');
    });

// T023 + T040: P16 — Dispute Management routes
Route::prefix('api/v1/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // Bank dispute callback (P16 ingress — provider-authenticated)
        Route::post('/bank/dispute-callback', \App\Modules\Payment\Http\Controllers\BankDisputeCallbackController::class)
            ->middleware('identity.require-permission:payment.callbacks.receive');

        // Customer dispute summary (ownership enforced in controller)
        Route::get('/intents/{intentId}/disputes', [\App\Modules\Payment\Http\Controllers\Customer\CustomerDisputeSummaryController::class, 'index'])
            ->middleware('identity.require-permission:payment.disputes.view');
    });

Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // Admin dispute case management
        Route::get('/dispute-cases', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'index'])
            ->middleware('identity.require-permission:payment.disputes.view');

        Route::get('/dispute-cases/{id}', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'show'])
            ->middleware('identity.require-permission:payment.disputes.view');

        Route::post('/dispute-cases/{id}/hold', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'hold'])
            ->middleware(['identity.require-permission:payment.disputes.manage', 'idempotency:require']);

        Route::post('/dispute-cases/{id}/outcome', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'outcome'])
            ->middleware(['identity.require-permission:payment.disputes.manage', 'idempotency:require']);

        Route::post('/dispute-cases/{id}/evidence', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'submitEvidence'])
            ->middleware('identity.require-permission:payment.disputes.manage');

        Route::post('/dispute-cases/{id}/finance-review', [\App\Modules\Payment\Http\Controllers\Admin\AdminDisputeCaseController::class, 'acknowledgeFinanceReview'])
            ->middleware('identity.require-permission:payment.disputes.finance_review');
    });

// P12: Bank Integration Configuration routes
Route::prefix('api/v1/admin/payments')
    ->middleware(['identity.auth', 'identity.require-permission:payment.configuration'])
    ->group(function (): void {
        // Integration profiles (read-only catalogue)
        Route::get('/integration-profiles', [IntegrationProfileAdminController::class, 'index']);

        // Bank management
        Route::get('/banks', [BankAdminController::class, 'index']);
        Route::post('/banks', [BankAdminController::class, 'store'])
            ->middleware('idempotency:require');
        Route::get('/banks/{bankId}', [BankAdminController::class, 'show']);
        Route::patch('/banks/{bankId}', [BankAdminController::class, 'update']);

        // Integration account management
        Route::get('/banks/{bankId}/accounts', [IntegrationAccountAdminController::class, 'index']);
        Route::post('/banks/{bankId}/accounts', [IntegrationAccountAdminController::class, 'store'])
            ->middleware('idempotency:require');
        Route::get('/banks/{bankId}/accounts/{accountId}', [IntegrationAccountAdminController::class, 'show']);
        Route::patch('/banks/{bankId}/accounts/{accountId}', [IntegrationAccountAdminController::class, 'update']);

        // API operation configuration
        Route::get('/banks/{bankId}/accounts/{accountId}/operations', [ApiOperationConfigAdminController::class, 'index']);
        Route::put('/banks/{bankId}/accounts/{accountId}/operations', [ApiOperationConfigAdminController::class, 'save'])
            ->middleware('idempotency:require');
        Route::post('/banks/{bankId}/accounts/{accountId}/operations/validate', [ApiOperationConfigAdminController::class, 'validate']);

        // Account lifecycle transitions
        Route::post('/banks/{bankId}/accounts/{accountId}/lifecycle', [BankLifecycleAdminController::class, 'transition'])
            ->middleware('idempotency:require');

        // Certification evidence and sandbox tests
        Route::get('/banks/{bankId}/accounts/{accountId}/evidence', [CertificationEvidenceAdminController::class, 'index']);
        Route::post('/banks/{bankId}/accounts/{accountId}/evidence', [CertificationEvidenceAdminController::class, 'store'])
            ->middleware('idempotency:require');
        Route::post('/banks/{bankId}/accounts/{accountId}/sandbox-test', [CertificationEvidenceAdminController::class, 'sandboxTest'])
            ->middleware('idempotency:require');

        // Credential rotation
        Route::post('/banks/{bankId}/accounts/{accountId}/rotate-credential', [CredentialRotationAdminController::class, 'rotate'])
            ->middleware('idempotency:require');
    });

// T129: Provider callback receiver — NO auth (called by external providers).
// Rate-limited to protect against DDoS. Raw body persisted before async processing.
Route::prefix('api/v1/payment')
    ->middleware(['throttle:payment-callback'])
    ->group(function (): void {
        Route::post('/callbacks/{providerCode}', [PaymentCallbackController::class, 'store']);
    });

// P17: Provider Statement Ingestion — system-to-system (provider-authenticated, not user auth)
Route::prefix('api/v1/payment')
    ->middleware(['throttle:payment-callback'])
    ->group(function (): void {
        Route::post('/provider/statement-callback', [BankStatementCallbackController::class, 'store'])
            ->middleware('identity.require-permission:payment.statement.ingest');
    });

// P18: Payout Beneficiaries, Requests, and Reservations
use App\Modules\Payment\Http\Controllers\Admin\DashboardSummaryAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentExportAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentHistoryAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PaymentIntentTimelineAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PayoutBeneficiaryAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PayoutRequestAdminController;
use App\Modules\Payment\Http\Controllers\Admin\PayoutReservationAdminController;
use App\Modules\Payment\Http\Controllers\Customer\PaymentHistoryController;
use App\Modules\Payment\Http\Controllers\Customer\ReceiptController;
use App\Modules\Payment\Http\Controllers\ReceiptDownloadController;

Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // Beneficiary management
        Route::get('/payout/beneficiaries', [PayoutBeneficiaryAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.payout.view');

        Route::post('/payout/beneficiaries', [PayoutBeneficiaryAdminController::class, 'store'])
            ->middleware(['identity.require-permission:payment.payout.manage_beneficiaries', 'idempotency:require']);

        Route::get('/payout/beneficiaries/{beneficiaryId}', [PayoutBeneficiaryAdminController::class, 'show'])
            ->middleware('identity.require-permission:payment.payout.view');

        Route::post('/payout/beneficiaries/{beneficiaryId}/verify', [PayoutBeneficiaryAdminController::class, 'verify'])
            ->middleware(['identity.require-permission:payment.payout.manage_beneficiaries', 'idempotency:require']);

        // Payout request lifecycle
        Route::get('/payout/requests', [PayoutRequestAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.payout.view');

        Route::post('/payout/requests', [PayoutRequestAdminController::class, 'initiate'])
            ->middleware(['identity.require-permission:payment.payout.initiate', 'idempotency:require']);

        Route::get('/payout/requests/{payoutId}', [PayoutRequestAdminController::class, 'show'])
            ->middleware('identity.require-permission:payment.payout.view');

        Route::get('/payout/requests/{payoutId}/balance', [PayoutRequestAdminController::class, 'balance'])
            ->middleware('identity.require-permission:payment.payout.view');

        // Reservation management
        Route::post('/payout/requests/{payoutId}/reserve', [PayoutReservationAdminController::class, 'createReservation'])
            ->middleware(['identity.require-permission:payment.payout.initiate', 'idempotency:require']);

        Route::post('/payout/requests/{payoutId}/approve-reservation', [PayoutReservationAdminController::class, 'approveReservation'])
            ->middleware(['identity.require-permission:payment.payout.approve', 'idempotency:require']);

        Route::post('/payout/requests/{payoutId}/reject-reservation', [PayoutReservationAdminController::class, 'rejectReservation'])
            ->middleware('identity.require-permission:payment.payout.approve');

        // P18 US3 stub — BLOCKED AD-14
        Route::post('/payout/requests/{payoutId}/execute', function (): \Illuminate\Http\JsonResponse {
            return response()->json(['message' => 'Not implemented — blocked pending AD-14 resolution'], 501);
        })->middleware('identity.require-permission:payment.payout.execute');

        // P18 US4 stub — BLOCKED AD-10 + LC14
        Route::post('/payout/requests/{payoutId}/confirm-settlement', function (): \Illuminate\Http\JsonResponse {
            return response()->json(['message' => 'Not implemented — blocked pending AD-10 and LC14 resolution'], 501);
        })->middleware('identity.require-permission:payment.payout.execute');
    });

// P17: Admin provider statement, clearing record, and reconciliation correction routes
Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        // Provider statements
        Route::get('/provider-statements', [ProviderStatementAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.statement.view');

        Route::get('/provider-statements/{statementId}', [ProviderStatementAdminController::class, 'show'])
            ->middleware('identity.require-permission:payment.statement.view');

        Route::post('/provider-statements/{statementId}/qualify', [ProviderStatementAdminController::class, 'qualify'])
            ->middleware(['identity.require-permission:payment.reconciliation.manage', 'idempotency:require']);

        // Clearing records
        Route::get('/clearing-records', [ClearingRecordAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.statement.view');

        // Reconciliation corrections
        Route::get('/reconciliation-corrections', [ReconciliationCorrectionAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.reconciliation.view_corrections');

        Route::post('/reconciliation-corrections', [ReconciliationCorrectionAdminController::class, 'submit'])
            ->middleware(['identity.require-permission:payment.reconciliation.view_corrections', 'idempotency:require']);

        Route::post('/reconciliation-corrections/{correctionId}/approve', [ReconciliationCorrectionAdminController::class, 'approve'])
            ->middleware('identity.require-permission:payment.reconciliation.approve_correction');

        Route::post('/reconciliation-corrections/{correctionId}/reject', [ReconciliationCorrectionAdminController::class, 'reject'])
            ->middleware('identity.require-permission:payment.reconciliation.approve_correction');

        // Acknowledge external — exception-level action (P17 US4-4)
        Route::post('/reconciliation-exceptions/{exceptionId}/acknowledge-external', [ReconciliationCorrectionAdminController::class, 'acknowledgeExternal'])
            ->middleware('identity.require-permission:payment.reconciliation.manage');
    });

// P19: Consumer payment history and receipts (authenticated)
Route::prefix('api/v1/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::get('/history', [PaymentHistoryController::class, 'index'])
            ->middleware('identity.require-permission:payment.history.read');

        Route::get('/receipts/{intentId}', [ReceiptController::class, 'show'])
            ->middleware('identity.require-permission:payment.history.read');

        Route::post('/receipts/{intentId}/download-token', [ReceiptController::class, 'issueDownloadToken'])
            ->middleware('identity.require-permission:payment.history.read');
    });

// P21: Finance Operations Dashboard and Intent Timeline (authenticated)
Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::get('/finance-summary', [DashboardSummaryAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.history.read.platform');

        Route::get('/intents/{id}/timeline', [PaymentIntentTimelineAdminController::class, 'show'])
            ->middleware('identity.require-permission:payment.intents.admin');

        Route::post('/intents/{id}/query-status', [PaymentIntentTimelineAdminController::class, 'queryStatus'])
            ->middleware('identity.require-permission:payment.intents.admin');
    });

// P19: Admin payment history and exports (authenticated)
Route::prefix('api/v1/admin/payment')
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::get('/history', [PaymentHistoryAdminController::class, 'index'])
            ->middleware('identity.require-permission:payment.history.read.organisation');

        Route::post('/exports', [PaymentExportAdminController::class, 'request'])
            ->middleware('identity.require-permission:payment.export.read.organisation');

        Route::get('/exports/{exportId}', [PaymentExportAdminController::class, 'status'])
            ->middleware('identity.require-permission:payment.export.read.organisation');
    });

// P19: Receipt and export download — NO auth (token IS the credential).
// Rate-limited to protect against abuse. Single-use token enforced at service layer.
Route::prefix('api/v1/payment')
    ->middleware(['throttle:60,1'])
    ->group(function (): void {
        Route::get('/receipts/download/{token}', [ReceiptDownloadController::class, 'download']);
        Route::get('/exports/download/{token}', [PaymentExportAdminController::class, 'download']);
    });
