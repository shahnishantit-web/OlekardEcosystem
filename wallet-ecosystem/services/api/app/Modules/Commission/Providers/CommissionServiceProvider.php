<?php

declare(strict_types=1);

namespace App\Modules\Commission\Providers;

use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\AgentFloat\Domain\Events\FloatCashInEvent;
use App\Modules\AgentFloat\Domain\Events\FloatCashOutEvent;
use App\Modules\Commission\Application\Services\CommissionCalculationService;
use App\Modules\Commission\Application\Services\CommissionPayoutService;
use App\Modules\Commission\Application\Services\CommissionReportService;
use App\Modules\Commission\Application\Services\CommissionReversalService;
use App\Modules\Commission\Application\Services\CommissionRuleService;
use App\Modules\Commission\Infrastructure\Listeners\AgentReactivatedCommissionListener;
use App\Modules\Commission\Infrastructure\Listeners\FloatCashInCommissionListener;
use App\Modules\Commission\Infrastructure\Listeners\FloatCashOutCommissionListener;
use App\Modules\Commission\Infrastructure\Repositories\CommissionProcessedEventRepository;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CommissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessedEventRepositoryInterface::class, CommissionProcessedEventRepository::class);

        $this->app->singleton(CommissionRuleService::class);
        $this->app->singleton(CommissionCalculationService::class);
        $this->app->singleton(CommissionReportService::class);
        $this->app->singleton(CommissionPayoutService::class);
        $this->app->singleton(CommissionReversalService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/commission.php'));

        Event::listen(FloatCashInEvent::class, FloatCashInCommissionListener::class);
        Event::listen(FloatCashOutEvent::class, FloatCashOutCommissionListener::class);
        Event::listen(AgentReactivatedEvent::class, AgentReactivatedCommissionListener::class);
    }
}
