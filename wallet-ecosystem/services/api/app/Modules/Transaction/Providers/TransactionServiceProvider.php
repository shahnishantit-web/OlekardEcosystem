<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Providers;

use App\Modules\Funding\Domain\Events\FundingTransactionCancelled;
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Domain\Events\FundingTransactionInitiated;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentFailedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentInitiatedEvent;
use App\Modules\Transaction\Application\EventHandlers\FundingTransactionEventHandler;
use App\Modules\Transaction\Application\EventHandlers\ServiceTransactionEventHandler;
use App\Modules\Transaction\Application\EventHandlers\TransferTransactionEventHandler;
use App\Modules\Transaction\Application\EventHandlers\WithdrawalTransactionEventHandler;
use App\Modules\Transaction\Application\Services\ReceiptService;
use App\Modules\Transaction\Application\Services\RefundService;
use App\Modules\Transaction\Application\Services\ReversalService;
use App\Modules\Transaction\Application\Services\SupportRefundAdapter;
use App\Modules\Transaction\Application\Services\TransactionModuleService;
use App\Modules\Transaction\Application\Services\TransactionProjectionService;
use App\Modules\Transaction\Domain\Contracts\NotificationServiceContract;
use App\Modules\Transaction\Domain\Contracts\SupportRefundContract;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use App\Modules\Transaction\Infrastructure\Stubs\NotificationServiceStub;
use App\Modules\Transfer\Domain\Events\TransferCancelled;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Domain\Events\TransferInitiated;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCancelled;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Domain\Events\WithdrawalInitiated;
use App\Modules\Withdrawal\Domain\Events\WithdrawalProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TransactionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupportRefundContract::class, SupportRefundAdapter::class);
        $this->app->singleton(TransactionProjectionRepository::class);
        $this->app->singleton(TransactionProjectionService::class);

        $this->app->singleton(FundingTransactionEventHandler::class);
        $this->app->singleton(TransferTransactionEventHandler::class);
        $this->app->singleton(WithdrawalTransactionEventHandler::class);
        $this->app->singleton(ServiceTransactionEventHandler::class);

        $this->app->singleton(ReversalService::class);
        $this->app->singleton(ReceiptService::class);
        $this->app->singleton(RefundService::class);

        $this->app->bind(NotificationServiceContract::class, NotificationServiceStub::class);
        $this->app->bind(TransactionModuleContract::class, TransactionModuleService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/transaction.php'));

        \App\Modules\Transaction\Application\EventHandlers\TransactionOutboxSubscriber::register([
            FundingTransactionInitiated::class, FundingTransactionCompleted::class,
            FundingTransactionFailed::class, FundingTransactionCancelled::class,
            TransferInitiated::class, TransferCompleted::class, TransferFailed::class, TransferCancelled::class,
            WithdrawalInitiated::class, WithdrawalProcessing::class, WithdrawalCompleted::class,
            WithdrawalFailed::class, WithdrawalCancelled::class,
            ServicePaymentInitiatedEvent::class, ServicePaymentCompletedEvent::class, ServicePaymentFailedEvent::class,
        ]);

        // Funding (E08) event bindings
        Event::listen(FundingTransactionInitiated::class, [FundingTransactionEventHandler::class, 'handleInitiated']);
        Event::listen(FundingTransactionCompleted::class, [FundingTransactionEventHandler::class, 'handleCompleted']);
        Event::listen(FundingTransactionFailed::class, [FundingTransactionEventHandler::class, 'handleFailed']);
        Event::listen(FundingTransactionCancelled::class, [FundingTransactionEventHandler::class, 'handleCancelled']);

        // Transfer (E09) event bindings
        Event::listen(TransferInitiated::class, [TransferTransactionEventHandler::class, 'handleInitiated']);
        Event::listen(TransferCompleted::class, [TransferTransactionEventHandler::class, 'handleCompleted']);
        Event::listen(TransferFailed::class, [TransferTransactionEventHandler::class, 'handleFailed']);
        Event::listen(TransferCancelled::class, [TransferTransactionEventHandler::class, 'handleCancelled']);

        // Withdrawal (E10) event bindings
        Event::listen(WithdrawalInitiated::class, [WithdrawalTransactionEventHandler::class, 'handleInitiated']);
        Event::listen(WithdrawalProcessing::class, [WithdrawalTransactionEventHandler::class, 'handleProcessing']);
        Event::listen(WithdrawalCompleted::class, [WithdrawalTransactionEventHandler::class, 'handleCompleted']);
        Event::listen(WithdrawalFailed::class, [WithdrawalTransactionEventHandler::class, 'handleFailed']);
        Event::listen(WithdrawalCancelled::class, [WithdrawalTransactionEventHandler::class, 'handleCancelled']);

        // Service (E15) event bindings
        Event::listen(ServicePaymentInitiatedEvent::class, [ServiceTransactionEventHandler::class, 'handleInitiated']);
        Event::listen(ServicePaymentCompletedEvent::class, [ServiceTransactionEventHandler::class, 'handleCompleted']);
        Event::listen(ServicePaymentFailedEvent::class, [ServiceTransactionEventHandler::class, 'handleFailed']);
    }
}
