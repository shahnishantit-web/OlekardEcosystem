<?php

declare(strict_types=1);

use App\Modules\Reporting\Infrastructure\Http\Controllers\CancelReportJobController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\CreateScheduleController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\DeactivateScheduleController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\GetExportController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\GetReportJobController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\GetScheduleController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\ListReportDefinitionsController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\ListReportJobsController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\ListScheduleRunsController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\ListSchedulesController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\RegenerateLinkController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\RequestExportController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\SubmitReportJobController;
use App\Modules\Reporting\Infrastructure\Http\Controllers\UpdateScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {
    // T029 — Report job routes
    Route::post('/reports/jobs', SubmitReportJobController::class);
    Route::get('/reports/jobs', ListReportJobsController::class);
    Route::get('/reports/jobs/{id}', GetReportJobController::class);
    Route::delete('/reports/jobs/{id}', CancelReportJobController::class);

    // T053 — Report export routes
    Route::post('/reports/jobs/{jobId}/exports', RequestExportController::class);
    Route::get('/reports/exports/{exportId}', GetExportController::class);
    Route::post('/reports/exports/{exportId}/regenerate-link', [RegenerateLinkController::class, '__invoke'])
        ->middleware('identity.require-permission:admin.write');

    // T068 — Report schedule routes
    Route::middleware(['identity.require-permission:reports.schedule'])->group(static function (): void {
        Route::get('/reports/schedules', ListSchedulesController::class);
        Route::post('/reports/schedules', CreateScheduleController::class);
        Route::get('/reports/schedules/{id}', GetScheduleController::class);
        Route::put('/reports/schedules/{id}', UpdateScheduleController::class);
        Route::delete('/reports/schedules/{id}', DeactivateScheduleController::class);
        Route::get('/reports/schedules/{id}/runs', ListScheduleRunsController::class);
    });

    // T072 — Report catalogue route
    Route::get('/reports/catalogue', ListReportDefinitionsController::class);
});
