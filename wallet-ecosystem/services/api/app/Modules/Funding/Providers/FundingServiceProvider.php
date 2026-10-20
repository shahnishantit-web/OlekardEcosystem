<?php

declare(strict_types=1);

namespace App\Modules\Funding\Providers;

use App\Modules\Funding\Application\FundingService;
use App\Modules\Funding\Application\Jobs\FundingInitiationJob;
use App\Modules\Funding\Application\Services\AgentCashInService;
use App\Modules\Funding\Application\Services\BankMatchingService;
use App\Modules\Funding\Application\Services\BankNotificationService;
use App\Modules\Funding\Application\Services\BankReconciliationService;
use App\Modules\Funding\Application\Services\BankReferenceGenerator;
use App\Modules\Funding\Application\Services\FundingCancellationService;
use App\Modules\Funding\Application\Services\FundingConfirmationService;
use App\Modules\Funding\Application\Services\FundingCreditService;
use App\Modules\Funding\Application\Services\FundingFeeService;
use App\Modules\Funding\Application\Services\FundingInitiationService;
use App\Modules\Funding\Application\Services\FundingLimitService;
use App\Modules\Funding\Application\Services\ProviderCallbackService;
use App\Modules\Funding\Console\ExpireStaleTransactionsCommand;
use App\Modules\Funding\Domain\Contracts\FundingModuleContract;
use App\Modules\Funding\Domain\Contracts\FundingProviderAdapter;
use App\Modules\Funding\Infrastructure\Adapters\MockFundingProviderAdapter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class FundingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module contract binding
        $this->app->bind(FundingModuleContract::class, FundingService::class);

        // Provider adapter (E08 mock only — real adapters in E1000 per TBD-E08-005)
        $this->app->bind(FundingProviderAdapter::class, MockFundingProviderAdapter::class);

        // Application services (resolved via constructor injection)
        $this->app->singleton(FundingFeeService::class);
        $this->app->singleton(FundingLimitService::class);
        $this->app->singleton(BankReferenceGenerator::class);
        $this->app->singleton(FundingInitiationService::class);
        $this->app->singleton(FundingConfirmationService::class);
        $this->app->singleton(FundingCancellationService::class);
        $this->app->singleton(FundingCreditService::class);
        $this->app->singleton(ProviderCallbackService::class);
        $this->app->singleton(AgentCashInService::class);
        $this->app->singleton(BankMatchingService::class);
        $this->app->singleton(BankNotificationService::class);
        $this->app->singleton(BankReconciliationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/funding.php'));
        $this->commands([ExpireStaleTransactionsCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('funding:expire-stale')->everyMinute()->withoutOverlapping();
        });

        // Do not access Redis/database during application boot: an unavailable
        // funding dependency must not prevent CORS preflights or unrelated APIs.
        // FundingCreditService validates the currency's float account before posting.
    }
}
