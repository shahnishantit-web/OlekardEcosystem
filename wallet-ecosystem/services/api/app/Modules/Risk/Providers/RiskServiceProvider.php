<?php

declare(strict_types=1);

namespace App\Modules\Risk\Providers;

use App\Modules\Commission\Domain\Events\CommissionPaidEvent;
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent;
use App\Modules\Risk\Application\Listeners\OnCommissionPaid;
use App\Modules\Risk\Application\Listeners\OnFundingTransactionCompleted;
use App\Modules\Risk\Application\Listeners\OnQrPaymentCompleted;
use App\Modules\Risk\Application\Listeners\OnServicePaymentCompleted;
use App\Modules\Risk\Application\Listeners\OnTransferCompleted;
use App\Modules\Risk\Application\Listeners\OnWithdrawalCompleted;
use App\Modules\Risk\Application\Services\AccountHoldService;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Application\Services\RiskEvaluatorService;
use App\Modules\Risk\Application\Services\RiskMetricsService;
use App\Modules\Risk\Application\Services\WatchlistScreenerService;
use App\Modules\Risk\Domain\Contracts\FiaReportingInterface;
use App\Modules\Risk\Domain\Contracts\RiskEvaluatorInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Contracts\WatchlistScreenerInterface;
use App\Modules\Risk\Infrastructure\Adapters\FiaReportingStub;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RiskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RiskEvaluatorInterface::class, RiskEvaluatorService::class);
        $this->app->bind(WatchlistScreenerInterface::class, WatchlistScreenerService::class);
        $this->app->bind(RiskMetricsInterface::class, RiskMetricsService::class);
        $this->app->bind(FiaReportingInterface::class, FiaReportingStub::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(AccountHoldService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/risk.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-security.php'));

        Event::listen(FundingTransactionCompleted::class, OnFundingTransactionCompleted::class);
        Event::listen(TransferCompleted::class, OnTransferCompleted::class);
        Event::listen(WithdrawalCompleted::class, OnWithdrawalCompleted::class);
        Event::listen(CommissionPaidEvent::class, OnCommissionPaid::class);
        Event::listen(ServicePaymentCompletedEvent::class, OnServicePaymentCompleted::class);
        Event::listen(QrPaymentCompletedEvent::class, OnQrPaymentCompleted::class);
    }
}
