<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub: no bindings. Business logic deferred to E27.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/notification.php'));
    }
}
