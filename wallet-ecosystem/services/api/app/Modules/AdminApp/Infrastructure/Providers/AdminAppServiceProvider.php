<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Providers;

use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Console\Commands\RefreshDashboardCacheCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class AdminAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PendingAdminActionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/admin-app.php'));
        $this->commands([RefreshDashboardCacheCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('adminapp:refresh-dashboard-cache')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
