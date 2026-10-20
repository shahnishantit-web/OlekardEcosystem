<?php

declare(strict_types=1);
use App\Modules\Risk\Infrastructure\Http\Controllers\AdminSecurityController;
use App\Modules\Security\Http\Controllers\SecurityAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/security')->middleware(['identity.auth'])->group(function (): void {
    Route::middleware('identity.require-permission:security.alerts.read')->group(function (): void {
        Route::get('alerts', [AdminSecurityController::class, 'index']);
        Route::get('alerts/{id}', [AdminSecurityController::class, 'show'])->whereUuid('id');
        Route::post('alerts/{id}/{action}', [AdminSecurityController::class, 'mutate'])->whereUuid('id')->whereIn('action', ['assign', 'notes', 'links', 'resolve', 'escalate'])->middleware('identity.require-permission:risk.manage_alerts');
        Route::post('alerts/{id}/freeze-requests', [AdminSecurityController::class, 'mutate'])->whereUuid('id')->defaults('action', 'freeze-requests')->middleware('identity.require-permission:risk.freeze_account');
        Route::post('alerts/{id}/freeze-requests/{requestId}/approve', [AdminSecurityController::class, 'approve'])->whereUuid('id')->whereUuid('requestId')->middleware('identity.require-permission:risk.freeze_account');
    });
    Route::get('findings', [SecurityAdminController::class, 'index'])->middleware('identity.require-permission:security.findings.read');
});
