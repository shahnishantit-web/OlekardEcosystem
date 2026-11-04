<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\OleKardFinancialTermsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/platform/financial-terms')->middleware(['identity.auth'])->group(static function (): void {
    Route::post('/', [OleKardFinancialTermsController::class, 'draft'])->middleware('idempotency:require-identity');
    Route::post('{versionId}/approve', [OleKardFinancialTermsController::class, 'approve'])->whereUuid('versionId')->middleware('idempotency:require-identity');
    Route::post('{versionId}/publish', [OleKardFinancialTermsController::class, 'publish'])->whereUuid('versionId')->middleware('idempotency:require-identity');
});

Route::post('v1/events/organisations/{organisationId}/events/{eventId}/financial-terms/{versionId}/accept',
    [OleKardFinancialTermsController::class, 'accept'])->whereUuid('organisationId')->whereUuid('eventId')->whereUuid('versionId')
    ->middleware(['identity.auth', 'idempotency:require-identity']);
