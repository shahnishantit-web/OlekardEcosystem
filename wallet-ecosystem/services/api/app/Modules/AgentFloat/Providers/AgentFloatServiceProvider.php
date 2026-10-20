<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Providers;

use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Agent\Domain\Events\AgentSuspendedEvent;
use App\Modules\AgentFloat\Application\Services\FloatAlertService;
use App\Modules\AgentFloat\Application\Services\FloatCashInService;
use App\Modules\AgentFloat\Application\Services\FloatCashOutService;
use App\Modules\AgentFloat\Application\Services\FloatFundingService;
use App\Modules\AgentFloat\Application\Services\FloatLimitService;
use App\Modules\AgentFloat\Application\Services\FloatTransactionService;
use App\Modules\AgentFloat\Application\Services\FloatWalletProvisioningService;
use App\Modules\AgentFloat\Infrastructure\Listeners\AgentReactivatedFloatListener;
use App\Modules\AgentFloat\Infrastructure\Listeners\AgentSuspendedFloatListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AgentFloatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FloatWalletProvisioningService::class);
        $this->app->singleton(FloatFundingService::class);
        $this->app->singleton(FloatCashInService::class);
        $this->app->singleton(FloatCashOutService::class);
        $this->app->singleton(FloatLimitService::class);
        $this->app->singleton(FloatAlertService::class);
        $this->app->singleton(FloatTransactionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/agent-float.php'));

        Event::listen(AgentSuspendedEvent::class, AgentSuspendedFloatListener::class);
        Event::listen(AgentReactivatedEvent::class, AgentReactivatedFloatListener::class);
    }
}
