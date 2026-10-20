<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Infrastructure\NullImplementations\NullIdentityModule;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Infrastructure\NullImplementations\NullLedgerModule;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Notification\Infrastructure\NullImplementations\NullNotificationModule;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Application\Services\DeadLetterService;
use App\Modules\Platform\Application\Services\IdempotencyService;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationApprovalRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationVersionRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\DeadLetterRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\DeadLetterServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Infrastructure\Repositories\EloquentConfigurationApprovalRepository;
use App\Modules\Platform\Infrastructure\Repositories\EloquentConfigurationChangeRequestRepository;
use App\Modules\Platform\Infrastructure\Repositories\EloquentConfigurationVersionRepository;
use App\Modules\Platform\Infrastructure\Repositories\EloquentDeadLetterRepository;
use App\Modules\Platform\Infrastructure\Archival\AuditArchivalDriverInterface;
use App\Modules\Platform\Infrastructure\Archival\NullArchivalDriver;
use App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface;
use App\Modules\Platform\Infrastructure\EventBus\InProcessDriver;
use App\Modules\Platform\Infrastructure\Metrics\InMemoryCollector;
use App\Modules\Platform\Application\Services\ExternalReferenceMappingService;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderReconciliationContract;
use App\Modules\Platform\Infrastructure\Security\EnvSecretProvider;
use App\Modules\Platform\Domain\Permissions\PlatformPermissions;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use App\Modules\Transaction\Infrastructure\NullImplementations\NullTransactionModule;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Infrastructure\NullImplementations\NullWalletModule;
use App\Listeners\DeadLetterJobFailureListener;
use App\Listeners\NotifyCustomerOnReversalListener;
use App\Modules\Funding\Application\Services\FundingOrphanRecoveryService;
use App\Modules\Platform\Application\Services\OrphanDetectionService;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use App\Modules\Transfer\Application\Services\TransferOrphanRecoveryService;
use App\Modules\Withdrawal\Application\Services\WithdrawalOrphanRecoveryService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Platform domain contracts
        $this->app->singleton(ConfigurationServiceInterface::class, ConfigurationService::class);
        $this->app->singleton(AuditServiceInterface::class, AuditService::class);
        $this->app->singleton(DeadLetterRepositoryInterface::class, EloquentDeadLetterRepository::class);
        $this->app->singleton(DeadLetterServiceInterface::class, DeadLetterService::class);
        $this->app->singleton(EventPublisherInterface::class, OutboxEventPublisher::class);
        $this->app->singleton(MetricsCollectorInterface::class, InMemoryCollector::class);
        $this->app->singleton(IdempotencyServiceInterface::class, IdempotencyService::class);
        $this->app->singleton(SecretProviderInterface::class, EnvSecretProvider::class);
        $this->app->singleton(AuditArchivalDriverInterface::class, NullArchivalDriver::class);
        $this->app->singleton(EventBusDriverInterface::class, InProcessDriver::class);

        // Bind NullImplementations for stub modules during Platform Foundation phase.
        // Each epic will replace these with real implementations in their ServiceProvider.
        $this->app->bind(IdentityModuleContract::class, NullIdentityModule::class);
        $this->app->bind(WalletModuleContract::class, NullWalletModule::class);
        $this->app->bind(LedgerModuleContract::class, NullLedgerModule::class);
        $this->app->bind(TransactionModuleContract::class, NullTransactionModule::class);
        $this->app->bind(NotificationModuleContract::class, NullNotificationModule::class);

        // Register test event command only in local/testing environments
        if ($this->app->environment(['local', 'testing'])) {
            $this->commands([\App\Console\Commands\TestEventCommand::class]);
        }

        $this->commands([
            \App\Console\Commands\CheckScopeCommand::class,
            \App\Console\Commands\ConfigurationEscalationCheckCommand::class,
            \App\Console\Commands\ConfigurationSeedDomainsCommand::class,
        ]);

        // E1000: Provider Adapter Foundation — additive registration
        $this->app->singleton(ExternalReferenceMappingService::class);
        $this->app->bind(ProviderReconciliationContract::class, ExternalReferenceMappingService::class);

        // E28: Configuration Management repositories and validators
        $this->app->singleton(ConfigurationChangeRequestRepositoryInterface::class, EloquentConfigurationChangeRequestRepository::class);
        $this->app->singleton(ConfigurationVersionRepositoryInterface::class, EloquentConfigurationVersionRepository::class);
        $this->app->singleton(ConfigurationApprovalRepositoryInterface::class, EloquentConfigurationApprovalRepository::class);

        // Domain validators bound with tagged container; resolved in ConfigurationChangeRequestService
        $this->app->tag([
            \App\Modules\Platform\Domain\Validators\FinancialPolicyConfigValidator::class,
            \App\Modules\Platform\Domain\Validators\OperationalPolicyConfigValidator::class,
            \App\Modules\Platform\Domain\Validators\GeographicConfigValidator::class,
        ], ConfigurationDomainValidatorInterface::class);

        // E27 T074/T075: OrphanDetectionService registry — Funding, Transfer, Withdrawal recovery services
        $this->app->singleton(FundingOrphanRecoveryService::class);
        $this->app->singleton(TransferOrphanRecoveryService::class);
        $this->app->singleton(WithdrawalOrphanRecoveryService::class);
        $this->app->singleton(OrphanDetectionService::class, function ($app) {
            return new OrphanDetectionService([
                $app->make(FundingOrphanRecoveryService::class),
                $app->make(TransferOrphanRecoveryService::class),
                $app->make(WithdrawalOrphanRecoveryService::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/configuration.php'));
        $this->loadRoutesFrom(base_path('routes/modules/reliability.php'));
        $this->registerPermissions();

        // E27 FR-013: Capture queue job failures into dead-letter store.
        Event::listen(JobFailed::class, DeadLetterJobFailureListener::class);

        // E27 T076: Notify customer when a transaction is reversed by orphan auto-recovery.
        Event::listen(TransactionReversedEvent::class, NotifyCustomerOnReversalListener::class);
    }

    private function registerPermissions(): void
    {
        foreach (PlatformPermissions::all() as $permission) {
            \Illuminate\Support\Facades\Log::debug('platform.permission.declared', [
                'permission' => $permission,
                'module' => 'Platform',
            ]);
        }
    }
}
