<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use App\Modules\Commission\Domain\Events\CommissionPaidEvent;
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Events\DeviceSuspiciousDetectedEvent;
use App\Modules\Identity\Domain\Events\NewDeviceDetectedEvent;
use App\Modules\Identity\Domain\Events\PinChangedEvent;
use App\Modules\Notification\Application\Listeners\AccountFrozenAmlListener;
use App\Modules\Notification\Application\Listeners\BillPaymentCompletedListener;
use App\Modules\Notification\Application\Listeners\CommissionCreditedListener;
use App\Modules\Notification\Application\Listeners\FundingCompletedListener;
use App\Modules\Notification\Application\Listeners\QrPaymentCompletedListener;
use App\Modules\Notification\Application\Listeners\SecurityAlertListener;
use App\Modules\Notification\Application\Listeners\SupportCaseNotificationListener;
use App\Modules\Notification\Application\Listeners\TransferCompletedListener;
use App\Modules\Notification\Application\Listeners\WithdrawalProcessedListener;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\EmailChannelInterface;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Notification\Domain\Contracts\PushChannelInterface;
use App\Modules\Notification\Domain\Contracts\SmsChannelInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Adapters\E03DeviceRegistryAdapter;
use App\Modules\Notification\Infrastructure\Adapters\E03UserContactAdapter;
use App\Modules\Notification\Infrastructure\Channels\LogEmailChannel;
use App\Modules\Notification\Infrastructure\Channels\LogPushChannel;
use App\Modules\Notification\Infrastructure\Channels\LogSmsChannel;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Notification\Application\Listeners\PaymentConfirmedReceiptListener;
use App\Modules\Notification\Application\Listeners\RefundConfirmedReceiptListener;
use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use App\Modules\Payment\Domain\Events\RefundConfirmedEvent;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushChannelInterface::class, LogPushChannel::class);
        $this->app->bind(SmsChannelInterface::class, LogSmsChannel::class);
        $this->app->bind(EmailChannelInterface::class, LogEmailChannel::class);
        $this->app->bind(PaymentNotificationServiceInterface::class, NotificationService::class);

        $this->app->bind(DeviceRegistryInterface::class, function ($app) {
            return new E03DeviceRegistryAdapter(
                $app->make(DeviceRepositoryInterface::class),
            );
        });

        $this->app->bind(UserContactServiceInterface::class, function ($app) {
            return new E03UserContactAdapter(
                $app->make(IdentityModuleContract::class),
            );
        });

        $this->app->singleton(NotificationMetrics::class, function ($app) {
            return new NotificationMetrics($app->make(MetricsCollectorInterface::class));
        });
    }

    public function boot(): void
    {
        Event::listen('ACCOUNT_FROZEN_AML', AccountFrozenAmlListener::class);
        Event::listen('DISPUTE_RESOLVED_WITH_REFUND', [SupportCaseNotificationListener::class, 'refund']);
        Event::listen('SUPPORT_CASE_ESCALATED', [SupportCaseNotificationListener::class, 'escalation']);
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/notification.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-notifications.php'));

        $this->configureHorizonQueues();
        $this->registerEventListeners();
    }

    private function registerEventListeners(): void
    {
        Event::listen(QrPaymentCompletedEvent::class, QrPaymentCompletedListener::class);
        Event::listen(FundingTransactionCompleted::class, FundingCompletedListener::class);
        Event::listen(TransferCompleted::class, TransferCompletedListener::class);
        Event::listen(WithdrawalCompleted::class, WithdrawalProcessedListener::class);
        Event::listen(CommissionPaidEvent::class, CommissionCreditedListener::class);
        Event::listen(ServicePaymentCompletedEvent::class, BillPaymentCompletedListener::class);

        // P19: Receipt notifications for payment confirmation and refund
        Event::listen(PaymentConfirmedEvent::class, PaymentConfirmedReceiptListener::class);
        Event::listen(RefundConfirmedEvent::class, RefundConfirmedReceiptListener::class);

        // Security events — all handled by SecurityAlertListener
        Event::listen(NewDeviceDetectedEvent::class, SecurityAlertListener::class);
        Event::listen(PinChangedEvent::class, SecurityAlertListener::class);
        Event::listen(DeviceSuspiciousDetectedEvent::class, SecurityAlertListener::class);
    }

    private function configureHorizonQueues(): void
    {
        if (! class_exists(Horizon::class)) {
            return;
        }

        Horizon::addQueues([
            'notifications-critical',
            'notifications-bulk',
        ]);
    }
}
