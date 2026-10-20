<?php

declare(strict_types=1);

use App\Modules\Audit\Infrastructure\Http\Controllers\AuditEventDetailController;
use App\Modules\Audit\Infrastructure\Http\Controllers\AuditEventListController;
use App\Modules\Audit\Infrastructure\Http\Controllers\ComplianceHistoryController;
use App\Modules\Audit\Infrastructure\Http\Controllers\ComplianceReportController;
use App\Modules\Audit\Infrastructure\Http\Controllers\ComplianceStatusController;
use App\Modules\Audit\Infrastructure\Http\Controllers\ComplianceTransactionController;
use App\Modules\Audit\Infrastructure\Http\Controllers\ComplianceDashboardController;
use App\Modules\Audit\Infrastructure\Http\Controllers\RetentionPolicyController;
use App\Modules\Audit\Infrastructure\Http\Controllers\SARController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E23 Audit & Compliance Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
| Routes are loaded by AuditServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Audit Events (read-only) ────────────────────────────────────────────
    Route::prefix('audit')->middleware(['identity.require-permission:audit.read'])->group(
        static function (): void {
            Route::get('events', AuditEventListController::class);
            Route::get('events/{id}', AuditEventDetailController::class);
        }
    );

    // ── Compliance Status & History ─────────────────────────────────────────
    Route::prefix('compliance')->middleware(['identity.require-permission:compliance.read'])->group(
        static function (): void {
            Route::get('dashboard', ComplianceDashboardController::class);
            Route::get('status/{userId}', ComplianceStatusController::class);
            Route::get('history/{userId}', ComplianceHistoryController::class);
            Route::get('transactions', ComplianceTransactionController::class);
        }
    );

    // ── Compliance Reports & SARs ───────────────────────────────────────────
    Route::prefix('compliance')->middleware(['identity.require-permission:compliance.report'])->group(
        static function (): void {
            Route::post('reports', [ComplianceReportController::class, 'store']);
            Route::get('reports/{id}', [ComplianceReportController::class, 'show']);
            Route::post('sars', [SARController::class, 'store']);
            Route::get('sars/{id}', [SARController::class, 'show']);
        }
    );

    // ── Retention Policies ──────────────────────────────────────────────────
    Route::prefix('compliance')->middleware(['identity.require-permission:compliance.config'])->group(
        static function (): void {
            Route::get('retention-policies', [RetentionPolicyController::class, 'index']);
            Route::post('retention-policies', [RetentionPolicyController::class, 'store']);
            Route::put('retention-policies/{id}', [RetentionPolicyController::class, 'update']);
        }
    );
});
