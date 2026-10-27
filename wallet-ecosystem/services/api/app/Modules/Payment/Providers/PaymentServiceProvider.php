<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\ApiOperationConfigService;
use App\Modules\Payment\Application\Services\BankAccountLifecycleService;
use App\Modules\Payment\Application\Services\BankAdminService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\CertificationEvidenceService;
use App\Modules\Payment\Application\Services\CredentialRotationService;
use App\Modules\Payment\Application\Services\ApiOperationConfigValidator;
use App\Modules\Payment\Application\Services\IntegrationAccountService;
use App\Modules\Payment\Application\Services\PaymentApplicationService;
use App\Modules\Payment\Application\Services\PaymentCallbackService;
use App\Modules\Payment\Application\Services\PaymentConfigDraftService;
use App\Modules\Payment\Application\Services\PaymentModuleConfigService;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Application\Services\PaymentProviderConfigValidator;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Application\Services\QuoteService;
use App\Modules\Payment\Application\Services\SandboxTestService;
use App\Modules\Payment\Application\Services\SsrfGuard;
use App\Modules\Payment\Domain\Contracts\AdapterAllowlistInterface;
use App\Modules\Payment\Domain\Contracts\BankAccountLifecycleServiceInterface;
use App\Modules\Payment\Domain\Contracts\BankRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Contracts\IntegrationProfileRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\SsrfGuardInterface;
use App\Modules\Payment\Infrastructure\Config\AdapterAllowlistConfig;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentConfigChangeDraftRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Application\Services\PaymentIdempotencyService;
use App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Infrastructure\Adapters\MockBankCallbackParser;
use App\Modules\Payment\Infrastructure\Adapters\MockBankPaymentAdapter;
use App\Modules\Payment\Infrastructure\Adapters\WalletPaymentAdapter;
use App\Modules\Payment\Infrastructure\Repositories\EloquentBankRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentIntegrationProfileRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentAttemptRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentConfigChangeDraftRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIntentRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentProviderRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentRefundRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIdempotencyRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentQuoteRepository;
use App\Modules\Payment\Application\EventHandlers\BankDisputeCallbackHandler;
use App\Modules\Payment\Application\EventHandlers\BankRefundCallbackHandler;
use App\Modules\Payment\Application\EventHandlers\BankSettlementBatchHandler;
use App\Modules\Payment\Application\EventHandlers\PaymentEventSubscriber;
use App\Modules\Payment\Application\Services\ClearingService;
use App\Modules\Payment\Application\Services\DisputeService;
use App\Modules\Payment\Application\Services\PayoutQualificationService;
use App\Modules\Payment\Application\Services\ProviderStatementService;
use App\Modules\Payment\Application\Services\ReconciliationCorrectionService;
use App\Modules\Payment\Application\Services\StatementMatchingService;
use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\ReconciliationCorrectionRepositoryInterface;
use App\Modules\Payment\Infrastructure\Repositories\EloquentClearingRecordRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentDisputeCaseRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentProviderStatementRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentReconciliationCorrectionRepository;
use App\Modules\Payment\Application\Services\PayoutBalanceService;
use App\Modules\Payment\Application\Services\PayoutBeneficiaryService;
use App\Modules\Payment\Application\Services\PayoutDomainEntitlementService;
use App\Modules\Payment\Application\Services\PayoutReservationService;
use App\Modules\Payment\Domain\Contracts\PayoutBeneficiaryRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PayoutDomainEntitlementGatewayInterface;
use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PayoutReservationRepositoryInterface;
use App\Modules\Payment\Infrastructure\Adapters\StubPayoutAdapter;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPayoutBeneficiaryRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPayoutRequestRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPayoutReservationRepository;
use App\Modules\Payment\Infrastructure\Stubs\DevPurposeStubResolver;
use App\Modules\Payment\Infrastructure\Stubs\PermissiveEntitlementGateway;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repository bindings
        $this->app->bind(PaymentIntentRepositoryInterface::class, EloquentPaymentIntentRepository::class);
        $this->app->bind(PaymentAttemptRepositoryInterface::class, EloquentPaymentAttemptRepository::class);
        $this->app->bind(PaymentRefundRepositoryInterface::class, EloquentPaymentRefundRepository::class);
        $this->app->bind(PaymentProviderRepositoryInterface::class, EloquentPaymentProviderRepository::class);
        $this->app->bind(PaymentConfigChangeDraftRepositoryInterface::class, EloquentPaymentConfigChangeDraftRepository::class);
        $this->app->bind(PaymentQuoteRepositoryInterface::class, EloquentPaymentQuoteRepository::class);

        // P05 bindings
        $this->app->bind(AdapterAllowlistInterface::class, AdapterAllowlistConfig::class);
        $this->app->singleton(EligibilityPipeline::class);

        // Application services
        $this->app->singleton(ProviderRegistryService::class);
        $this->app->singleton(PaymentProviderConfigValidator::class);
        $this->app->singleton(BusinessPurposeResolverRegistry::class);
        $this->app->singleton(QuoteService::class);
        $this->app->singleton(PaymentOrchestrationService::class);
        $this->app->bind(\App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor::class, PaymentOrchestrationService::class);
        $this->app->singleton(PaymentCallbackService::class);
        $this->app->bind('integration.webhook.factory.payment', \App\Modules\Payment\Infrastructure\Adapters\PaymentWebhookFactory::class);
        $this->app->bind('integration.webhook.handler.payment_attempt', \App\Modules\Payment\Application\Services\PaymentWebhookHandler::class);
        $this->app->singleton(ReconciliationExceptionService::class);
        $this->app->singleton(RefundService::class);
        $this->app->singleton(PaymentProviderAdminService::class);
        $this->app->singleton(PaymentConfigDraftService::class);

        // P20: Module availability configuration
        $this->app->singleton(PaymentModuleConfigService::class);

        // P07: Idempotency repository and service
        $this->app->bind(PaymentIdempotencyRepositoryInterface::class, EloquentPaymentIdempotencyRepository::class);
        $this->app->singleton(PaymentIdempotencyService::class);

        // T145: PaymentModuleContract → PaymentApplicationService
        $this->app->bind(PaymentModuleContract::class, PaymentApplicationService::class);

        // P12: Bank Integration Configuration bindings
        $this->app->bind(BankRepositoryInterface::class, EloquentBankRepository::class);
        $this->app->bind(IntegrationProfileRepositoryInterface::class, EloquentIntegrationProfileRepository::class);
        $this->app->bind(BankAccountLifecycleServiceInterface::class, BankAccountLifecycleService::class);
        $this->app->bind(SsrfGuardInterface::class, SsrfGuard::class);
        $this->app->singleton(BankAdminService::class);
        $this->app->singleton(IntegrationAccountService::class);
        $this->app->singleton(SsrfGuard::class);
        $this->app->singleton(ApiOperationConfigValidator::class);
        $this->app->singleton(ApiOperationConfigService::class);
        $this->app->singleton(BankAccountLifecycleService::class);
        $this->app->singleton(CertificationEvidenceService::class);
        $this->app->singleton(CredentialRotationService::class);
        $this->app->singleton(SandboxTestService::class);

        // P16: Dispute Management
        $this->app->bind(PaymentDisputeCaseRepositoryInterface::class, EloquentPaymentDisputeCaseRepository::class);
        $this->app->singleton(DisputeService::class);

        // P17: Reconciliation Clearing and Provider Settlement
        $this->app->bind(ProviderStatementRepositoryInterface::class, EloquentProviderStatementRepository::class);
        $this->app->bind(ClearingRecordRepositoryInterface::class, EloquentClearingRecordRepository::class);
        $this->app->bind(ReconciliationCorrectionRepositoryInterface::class, EloquentReconciliationCorrectionRepository::class);
        $this->app->singleton(ProviderStatementService::class);
        $this->app->singleton(StatementMatchingService::class);
        $this->app->singleton(ClearingService::class);
        $this->app->singleton(ReconciliationCorrectionService::class);
        $this->app->singleton(PayoutQualificationService::class);

        // P18: Payout Lifecycle — Beneficiary Verification and Funds Reservation
        $this->app->bind(PayoutBeneficiaryRepositoryInterface::class, EloquentPayoutBeneficiaryRepository::class);
        $this->app->bind(PayoutRequestRepositoryInterface::class, EloquentPayoutRequestRepository::class);
        $this->app->bind(PayoutReservationRepositoryInterface::class, EloquentPayoutReservationRepository::class);
        $this->app->bind(PayoutDomainEntitlementGatewayInterface::class, PermissiveEntitlementGateway::class);
        $this->app->singleton(PayoutBeneficiaryService::class);
        $this->app->singleton(PayoutDomainEntitlementService::class);
        $this->app->singleton(PayoutBalanceService::class);
        $this->app->singleton(PayoutReservationService::class);

        // P18: StubPayoutAdapter — DEV MOCK only; NOT FOR PRODUCTION
        if ($this->app->environment('local', 'development', 'testing')) {
            $this->app->bind('payment.payout.adapter.stub', StubPayoutAdapter::class);
        }

        // Payment adapter registry — keyed by 'payment.adapter.{identifier}'
        // T096: WalletPaymentAdapter (wallet payments are NOT production-blocked)
        $this->app->bind('payment.adapter.olekard_wallet', WalletPaymentAdapter::class);

        // T102: MockBankPaymentAdapter — production-blocked by constructor AND by this conditional binding
        if ($this->app->environment('local', 'development', 'testing')) {
            $this->app->bind('payment.adapter.mock_bank', MockBankPaymentAdapter::class);
            // T109: MockBankCallbackParser — registered only in non-production
            $this->app->bind('payment.callback_parser.mock_bank', MockBankCallbackParser::class);
        } else {
            $this->app->bind('payment.adapter.mock_bank', static function (): never {
                throw new \App\Modules\Payment\Domain\Exceptions\ProductionBlockedException('mock_bank');
            });
            $this->app->bind('payment.callback_parser.mock_bank', static function (): never {
                throw new \App\Modules\Payment\Domain\Exceptions\ProductionBlockedException('mock_bank_callback_parser');
            });
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/payment.php'));
        $this->app['events']->subscribe(PaymentEventSubscriber::class);

        // P15: Inbox consumer for bank.refund.callback events
        $this->app['events']->listen('bank.refund.callback', BankRefundCallbackHandler::class);

        // P16: Inbox consumer for bank.dispute.callback events
        $this->app['events']->listen('bank.dispute.callback', BankDisputeCallbackHandler::class);

        // P17: Inbox consumer for bank.settlement.batch events
        $this->app['events']->listen('bank.settlement.batch', BankSettlementBatchHandler::class);

        // P04: Register DevPurposeStubResolver for local/development environments only.
        // Blocked by constructor in production/staging; never registered there.
        if ($this->app->environment('local', 'development', 'testing')) {
            $this->app->make(BusinessPurposeResolverRegistry::class)
                ->register('dev_test_purpose', new DevPurposeStubResolver());
        }

        // Rate limiter definitions — T152 (S13) will configure thresholds via ConfigurationService
        RateLimiter::for('payment-intent', static function (Request $request): \Illuminate\Cache\RateLimiting\Limit {
            /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
            $user = $request->user();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($user?->id ?? $request->ip());
        });

        RateLimiter::for('payment-callback', static function (Request $request): \Illuminate\Cache\RateLimiting\Limit {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(100)->by($request->ip());
        });
    }

    /**
     * Register a BusinessPurposeResolver for a given purpose type.
     * Called by horizontal modules in their own ServiceProvider::boot().
     */
    public static function registerPurposeResolver(string $purposeType, string $resolverClass): void
    {
        app(BusinessPurposeResolverRegistry::class)->register($purposeType, $resolverClass);
    }
}
