<?php

declare(strict_types=1);

use App\Modules\Ops\Http\Controllers\Admin\AdminAlertController;
use App\Modules\Ops\Http\Controllers\Admin\AdminAlertRuleController;
use App\Modules\Ops\Http\Controllers\Admin\AdminBackupJobController;
use App\Modules\Ops\Http\Controllers\Admin\AdminDeploymentController;
use App\Modules\Ops\Http\Controllers\Admin\AdminDrController;
use App\Modules\Ops\Http\Controllers\Admin\AdminLogController;
use App\Modules\Ops\Http\Controllers\Admin\AdminMetricsController;
use App\Modules\Ops\Http\Controllers\Admin\AdminProviderMonitoringController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E30 DevOps & Operations — Admin Routes
|--------------------------------------------------------------------------
| Auth middleware: identity.auth (JWT required).
| Permission middleware: identity.require-permission:{slug}.
*/

Route::prefix('v1/admin/ops')->middleware(['identity.auth'])->group(static function (): void {

    // ── Logs ─────────────────────────────────────────────────────────────────
    Route::get('logs', [AdminLogController::class, 'index'])
        ->middleware('identity.require-permission:ops.view_logs');

    // ── Metrics ───────────────────────────────────────────────────────────────
    Route::get('metrics', [AdminMetricsController::class, 'index'])
        ->middleware('identity.require-permission:ops.view_metrics');

    // ── Provider Monitoring ───────────────────────────────────────────────────
    Route::middleware('identity.require-permission:ops.view_health')->group(static function (): void {
        Route::get('providers', [AdminProviderMonitoringController::class, 'index']);
        Route::get('providers/{providerType}', [AdminProviderMonitoringController::class, 'show']);
        Route::get('providers/{providerType}/history', [AdminProviderMonitoringController::class, 'history']);
    });

    // ── Alert Rules ───────────────────────────────────────────────────────────
    Route::prefix('alert-rules')->middleware('identity.require-permission:ops.manage_alerts')->group(static function (): void {
        Route::get('', [AdminAlertRuleController::class, 'index']);
        Route::post('', [AdminAlertRuleController::class, 'store']);
        Route::get('{id}', [AdminAlertRuleController::class, 'show']);
        Route::put('{id}', [AdminAlertRuleController::class, 'update']);
        Route::delete('{id}', [AdminAlertRuleController::class, 'destroy']);
    });

    // ── Alerts ────────────────────────────────────────────────────────────────
    Route::get('alerts', [AdminAlertController::class, 'index'])
        ->middleware('identity.require-permission:ops.manage_alerts');

    // ── Backup Jobs ───────────────────────────────────────────────────────────
    Route::prefix('backup-jobs')->middleware('identity.require-permission:ops.manage_backup')->group(static function (): void {
        Route::get('', [AdminBackupJobController::class, 'index']);
        Route::post('', [AdminBackupJobController::class, 'store']);
        Route::post('{id}/verify', [AdminBackupJobController::class, 'verify']);
    });

    // ── Disaster Recovery ─────────────────────────────────────────────────────
    Route::prefix('dr')->middleware('identity.require-permission:ops.manage_backup')->group(static function (): void {
        Route::get('status', [AdminDrController::class, 'status']);
        Route::post('tests', [AdminDrController::class, 'recordTest']);
    });

    // ── Deployments ───────────────────────────────────────────────────────────
    Route::prefix('deployments')->middleware('identity.require-permission:ops.manage_deployments')->group(static function (): void {
        Route::get('versions', [AdminDeploymentController::class, 'versions']);
        Route::get('rollbacks', [AdminDeploymentController::class, 'rollbacks']);
        Route::post('rollback', [AdminDeploymentController::class, 'rollback']);
    });
});
