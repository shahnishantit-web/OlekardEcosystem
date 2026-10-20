<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\PaymentApplicationService;
use App\Modules\Payment\Application\Services\PaymentCallbackService;
use App\Modules\Payment\Application\Services\PaymentProviderConfigValidator;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Application\Services\ProviderRegistryService;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Infrastructure\Adapters\MockBankCallbackParser;
use App\Modules\Payment\Infrastructure\Adapters\MockBankPaymentAdapter;
use App\Modules\Payment\Infrastructure\Adapters\WalletPaymentAdapter;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentAttemptRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIntentRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentProviderRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentRefundRepository;
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

        // Application services
        $this->app->singleton(ProviderRegistryService::class);
        $this->app->singleton(PaymentProviderConfigValidator::class);
        $this->app->singleton(BusinessPurposeResolverRegistry::class);
        $this->app->singleton(PaymentOrchestrationService::class);
        $this->app->singleton(PaymentCallbackService::class);
        $this->app->singleton(ReconciliationExceptionService::class);
        $this->app->singleton(RefundService::class);

        // T145: PaymentModuleContract → PaymentApplicationService
        $this->app->bind(PaymentModuleContract::class, PaymentApplicationService::class);

        // Payment adapter registry — keyed by 'payment.adapter.{identifier}'
        // T096: WalletPaymentAdapter (wallet payments are NOT production-blocked)
        $this->app->bind('payment.adapter.olekard_wallet', WalletPaymentAdapter::class);

        // T102: MockBankPaymentAdapter — production-blocked by constructor AND by this conditional binding
        if (! $this->app->isProduction()) {
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
