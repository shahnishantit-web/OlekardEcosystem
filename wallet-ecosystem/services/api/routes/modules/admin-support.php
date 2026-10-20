<?php

declare(strict_types=1);
use App\Modules\Support\Infrastructure\Http\Controllers\AdminSupportController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/support')->middleware(['identity.auth', 'identity.require-permission:support.investigate'])->group(function (): void {
    Route::get('summary', [C::class, 'summary']);
    Route::get('cases', [C::class, 'index']);
    Route::get('cases/{id}', [C::class, 'show'])->whereUuid('id');
    Route::patch('cases/{id}', [C::class, 'update'])->whereUuid('id');
    Route::post('cases/{id}/resolve', [C::class, 'resolve'])->whereUuid('id')->middleware('identity.require-permission:support.resolve');
    Route::post('cases/{id}/refund', [C::class, 'refund'])->whereUuid('id')->middleware(['identity.require-permission:dispute.refund', 'throttle:10,1']);
    Route::post('cases/{id}/attachments', [C::class, 'attach'])->whereUuid('id')->middleware('throttle:10,1');
    Route::get('cases/{id}/attachments/{entryId}', [C::class, 'download'])->whereUuid(['id', 'entryId']);
});
