<?php

declare(strict_types=1);

namespace App\Modules\Fees\Providers;

use App\Modules\Fees\Application\Services\FeeCalculationService;
use App\Modules\Fees\Application\Services\FeeLedgerService;
use App\Modules\Fees\Application\Services\FeeRuleService;
use App\Modules\Fees\Application\Services\FeesServiceFacade;
use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use Illuminate\Support\ServiceProvider;

final class FeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeeRuleService::class);
        $this->app->singleton(FeeCalculationService::class);
        $this->app->singleton(FeeLedgerService::class);
        $this->app->bind(FeesModuleContract::class, FeesServiceFacade::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/fees.php'));
    }
}
