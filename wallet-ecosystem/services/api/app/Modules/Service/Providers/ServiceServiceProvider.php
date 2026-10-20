<?php

declare(strict_types=1);

namespace App\Modules\Service\Providers;

use App\Modules\Service\Application\Jobs\AbandonStaleTransactionsJob;
use App\Modules\Service\Application\Jobs\ReQueryTimeoutTransactionJob;
use App\Modules\Service\Application\Jobs\TransitionTimedOutPaymentsJob;
use App\Modules\Service\Application\Services\AdminResolutionService;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceDiscoveryService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Application\Services\ServiceValidationService;
use App\Modules\Service\Console\AbandonStaleServiceTransactionsCommand;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Infrastructure\Adapters\MockServiceProviderAdapter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ServiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Provider adapter — dev/test mock only; real adapters TBD per Constitution Principle 2
        $this->app->bind(ServiceProviderAdapter::class, MockServiceProviderAdapter::class);

        $this->app->singleton(ServiceFeeService::class);
        $this->app->singleton(ServiceReceiptService::class);
        $this->app->singleton(ServiceCatalogService::class);
        $this->app->singleton(ServiceDiscoveryService::class);
        $this->app->singleton(ServiceValidationService::class);
        $this->app->singleton(ServicePaymentService::class);
        $this->app->singleton(ServiceOutcomeService::class);
        $this->app->singleton(AdminResolutionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/service.php'));
        $this->commands([AbandonStaleServiceTransactionsCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new AbandonStaleTransactionsJob())
                ->everyFifteenMinutes()
                ->withoutOverlapping();

            // Transitions pending_provider → timeout_pending_review; must run before ReQuery
            $schedule->job(new TransitionTimedOutPaymentsJob())
                ->everyTwoMinutes()
                ->withoutOverlapping();

            $schedule->job(new ReQueryTimeoutTransactionJob())
                ->everyFiveMinutes()
                ->withoutOverlapping();
        });
    }
}
