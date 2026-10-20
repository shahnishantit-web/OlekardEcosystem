<?php

declare(strict_types=1);
use App\Modules\Notification\Infrastructure\Http\Controllers\AdminNotificationController as Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/notifications')->middleware('identity.auth')->group(function (): void {
    Route::get('templates', [Controller::class, 'templates'])->middleware('identity.require-permission:notifications.templates.read');
    Route::patch('templates', [Controller::class, 'edit'])->middleware('identity.require-permission:notifications.templates.write');
    Route::post('templates/preview', [Controller::class, 'preview'])->middleware('identity.require-permission:notifications.templates.read');
    Route::post('templates/test', [Controller::class, 'test'])->middleware(['identity.require-permission:notifications.templates.write', 'throttle:5,1']);
    Route::get('log', [Controller::class, 'log'])->middleware('identity.require-permission:notifications.log.read');
    Route::post('retry/{id}', [Controller::class, 'retry'])->whereUuid('id')->middleware(['identity.require-permission:notifications.retry', 'throttle:20,1']);
    Route::get('rules', [Controller::class, 'rules'])->middleware('identity.require-permission:notifications.rules.read');
    Route::patch('rules', [Controller::class, 'updateRule'])->middleware('identity.require-permission:notifications.rules.write');
});
