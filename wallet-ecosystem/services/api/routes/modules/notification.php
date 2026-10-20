<?php

declare(strict_types=1);

use App\Modules\Notification\Infrastructure\Http\Controllers\InAppNotificationController;
use App\Modules\Notification\Infrastructure\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

// ─── Notification inbox ───────────────────────────────────────────────────────
foreach (['v1/notifications', 'api/v1/notifications'] as $prefix) {
Route::prefix($prefix)
    ->middleware(['identity.auth'])
    ->group(function (): void {
        Route::get('/inbox',               [InAppNotificationController::class, 'index']);
        Route::get('/inbox/unread-count',  [InAppNotificationController::class, 'unreadCount']);
        Route::get('/inbox/{id}',          [InAppNotificationController::class, 'show']);
        Route::patch('/inbox/read',        [InAppNotificationController::class, 'markRead']);

        Route::get('/preferences',  [NotificationPreferenceController::class, 'show']);
        Route::put('/preferences',  [NotificationPreferenceController::class, 'update']);
    });
}
