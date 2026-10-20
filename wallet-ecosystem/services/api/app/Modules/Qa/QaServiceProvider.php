<?php

declare(strict_types=1);

namespace App\Modules\Qa;

use App\Modules\Qa\Application\QaExecutorRegistry;
use App\Modules\Qa\Application\Services\QaRunService;
use App\Modules\Qa\Console\QaSeedCommand;
use Illuminate\Support\ServiceProvider;

final class QaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QaRunService::class);
        $this->app->singleton(QaExecutorRegistry::class);

        $this->mergeConfigFrom(config_path('qa.php'), 'qa');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/qa.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([QaSeedCommand::class]);
        }
    }
}
