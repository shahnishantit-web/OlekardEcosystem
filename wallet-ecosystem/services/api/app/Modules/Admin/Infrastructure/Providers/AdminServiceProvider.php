<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Providers;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Admin\Application\Services\AdminMetricsService;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdminMetricsInterface::class, AdminMetricsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/admin.php'));
    }
}
