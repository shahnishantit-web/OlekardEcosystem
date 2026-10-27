<?php

declare(strict_types=1);

use App\Modules\CommunityFuneral\Http\Controllers\FuneralAssistanceController;
use App\Modules\CommunityFuneral\Http\Middleware\FuneralReadinessGate;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/community/funeral')->middleware(['identity.auth', FuneralReadinessGate::class])->group(function (): void {
    Route::post('/communities', [FuneralAssistanceController::class, 'createCommunity'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/communities/{communityId}/staff', [FuneralAssistanceController::class, 'addStaff'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/communities/{communityId}/households', [FuneralAssistanceController::class, 'createHousehold'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/households/{householdId}/review', [FuneralAssistanceController::class, 'reviewHousehold'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::post('/households/{householdId}/representatives', [FuneralAssistanceController::class, 'addRepresentative'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/households/{householdId}/representatives/{representativeId}/verify', [FuneralAssistanceController::class, 'verifyRepresentative'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::post('/households/{householdId}/representatives/{representativeId}/revoke', [FuneralAssistanceController::class, 'revokeRepresentative'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::post('/communities/{communityId}/collections', [FuneralAssistanceController::class, 'createCollection'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::patch('/collections/{collectionId}/beneficiary', [FuneralAssistanceController::class, 'changeBeneficiary'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/collections/{collectionId}/review', [FuneralAssistanceController::class, 'approve'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::post('/collections/{collectionId}/activate', [FuneralAssistanceController::class, 'activate'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::get('/my/obligations', [FuneralAssistanceController::class, 'myObligations'])
        ->middleware('identity.require-permission:community.funeral.household.read');
    Route::post('/collections/{collectionId}/close', [FuneralAssistanceController::class, 'close'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::get('/collections/{collectionId}/aggregate-report', [FuneralAssistanceController::class, 'report'])
        ->middleware('identity.require-permission:community.funeral.report');
    Route::post('/collections/{collectionId}/carry-forward', [FuneralAssistanceController::class, 'requestCarryForward'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/collections/{collectionId}/carry-forward/approve', [FuneralAssistanceController::class, 'approveCarryForward'])
        ->middleware(['identity.require-permission:community.funeral.review', 'idempotency:require-identity']);
    Route::post('/collections/{collectionId}/archive', [FuneralAssistanceController::class, 'archive'])
        ->middleware(['identity.require-permission:community.funeral.manage', 'idempotency:require-identity']);
    Route::post('/collections/{collectionId}/payout-request', [FuneralAssistanceController::class, 'payoutBlocked'])
        ->middleware(['identity.require-permission:community.funeral.payout.request', 'idempotency:require-identity']);
});
