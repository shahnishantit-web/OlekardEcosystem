<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Providers;

use App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskService;
use App\Modules\Withdrawal\Application\Services\WithdrawalCancellationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalCallbackService;
use App\Modules\Withdrawal\Application\Services\WithdrawalConfirmationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalExpiryService;
use App\Modules\Withdrawal\Application\Services\WithdrawalFeeService;
use App\Modules\Withdrawal\Application\Services\WithdrawalInitiationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalLimitService;
use App\Modules\Withdrawal\Application\Services\WithdrawalMetricsService;
use App\Modules\Withdrawal\Application\Services\WithdrawalReceiptService;
use App\Modules\Withdrawal\Application\Commands\ExpireStaleWithdrawalsCommand;
use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;
use App\Modules\Withdrawal\Infrastructure\Adapters\MockPayoutProviderAdapter;
use App\Modules\Withdrawal\Infrastructure\Stubs\WithdrawalRiskServiceStub;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class WithdrawalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayoutProviderAdapter::class, MockPayoutProviderAdapter::class);
        $this->app->bind(WithdrawalRiskService::class, WithdrawalRiskServiceStub::class);

        $this->app->singleton(WithdrawalFeeService::class);
        $this->app->singleton(WithdrawalLimitService::class);
        $this->app->singleton(WithdrawalMetricsService::class);
        $this->app->singleton(WithdrawalInitiationService::class);
        $this->app->singleton(WithdrawalConfirmationService::class);
        $this->app->singleton(WithdrawalCancellationService::class);
        $this->app->singleton(WithdrawalCallbackService::class);
        $this->app->singleton(WithdrawalExpiryService::class);
        $this->app->singleton(WithdrawalReceiptService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/withdrawal.php'));
        $this->commands([ExpireStaleWithdrawalsCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('withdrawal:expire-stale')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
