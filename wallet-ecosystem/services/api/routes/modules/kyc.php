<?php

declare(strict_types=1);

use App\Modules\Kyc\Infrastructure\Http\Controllers\KycCaseController;
use App\Modules\Kyc\Infrastructure\Http\Controllers\KycDocumentController;
use App\Modules\Kyc\Infrastructure\Http\Controllers\KycReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E05 KYC & Identity Verification Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/kyc/.
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
| Routes are loaded by KycServiceProvider::boot().
*/

Route::prefix('v1/kyc')->middleware(['identity.auth'])->group(static function (): void {

    // ── Customer / Agent — KYC Case Intake ─────────────────────────────────

    // T039: US-KYC-001 — Start KYC case (idempotent)
    Route::post('cases', [KycCaseController::class, 'start'])
        ->middleware('identity.require-permission:kyc.case.create');

    // T045: US-KYC-002 — Capture personal information
    Route::put('cases/{id}/personal-information', [KycCaseController::class, 'capturePersonalInfo'])
        ->middleware('identity.require-permission:kyc.case.update');

    // T056: US-KYC-003 — Upload document (all types via single endpoint)
    Route::post('cases/{id}/documents', [KycDocumentController::class, 'upload'])
        ->middleware('identity.require-permission:kyc.case.update');

    // T064: US-KYC-008 — Submit case for verification (idempotent)
    Route::post('cases/{id}/submit', [KycCaseController::class, 'submit'])
        ->middleware('identity.require-permission:kyc.case.update');

    // T088: US-KYC-014 — Resubmit rejected/needs-info case (idempotent)
    Route::post('cases/{id}/resubmit', [KycCaseController::class, 'resubmit'])
        ->middleware('identity.require-permission:kyc.case.update');

    // T088: US-KYC-015 — Track own KYC status (must be before /{id} to avoid routing conflict)
    Route::get('cases/my', [KycCaseController::class, 'myStatus'])
        ->middleware('identity.require-permission:kyc.case.read');

    // T100: US-KYC-018 — Trigger reverification (Admin/Compliance Officer)
    Route::post('cases/{id}/reverify', [KycCaseController::class, 'reverify'])
        ->middleware('identity.require-permission:kyc.case.manage');

    // ── Stateless Document Validation ──────────────────────────────────────

    // T057: US-KYC-006 — Validate document format (no DB write)
    Route::post('documents/validate-format', [KycDocumentController::class, 'validateFormat'])
        ->middleware('identity.require-permission:kyc.case.update');

    // T058: US-KYC-007 — Validate document expiry (no DB write)
    Route::post('documents/validate-expiry', [KycDocumentController::class, 'validateExpiry'])
        ->middleware('identity.require-permission:kyc.case.update');

    // ── Compliance Officer — Manual Review ─────────────────────────────────

    // T080: US-KYC-010 — List all cases (Compliance Officer)
    Route::get('cases', [KycReviewController::class, 'index'])
        ->middleware('identity.require-permission:kyc.case.review');

    // T080: US-KYC-010 — View case details (own case for Customer; any case for Compliance Officer)
    Route::get('cases/{id}', [KycReviewController::class, 'show'])
        ->middleware('identity.require-permission:kyc.case.read');

    // T080: US-KYC-011 — Approve KYC case
    Route::post('cases/{id}/approve', [KycReviewController::class, 'approve'])
        ->middleware('identity.require-permission:kyc.case.approve');

    // T080: US-KYC-012 — Reject KYC case with reason
    Route::post('cases/{id}/reject', [KycReviewController::class, 'reject'])
        ->middleware('identity.require-permission:kyc.case.review');

    // T080: US-KYC-013 — Request additional information
    Route::post('cases/{id}/request-info', [KycReviewController::class, 'requestInfo'])
        ->middleware('identity.require-permission:kyc.case.review');
});
