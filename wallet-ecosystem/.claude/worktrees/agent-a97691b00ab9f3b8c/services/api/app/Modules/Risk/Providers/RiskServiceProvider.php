<?php

declare(strict_types=1);

namespace App\Modules\Risk\Providers;

use Illuminate\Support\ServiceProvider;

final class RiskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub: no bindings. Business logic deferred to E12.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/risk.php'));
    }
}
