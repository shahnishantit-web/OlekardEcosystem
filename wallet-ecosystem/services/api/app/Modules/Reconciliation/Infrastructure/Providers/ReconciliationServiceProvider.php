<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Providers;

use App\Modules\Reconciliation\Application\Adapters\CsvProviderFileAdapter;
use App\Modules\Reconciliation\Application\Adapters\HmacWebhookAuthenticator;
use App\Modules\Reconciliation\Application\Adapters\NullSettlementAdapter;
use App\Modules\Reconciliation\Application\Services\ReconciliationMetricsService;
use App\Modules\Reconciliation\Application\Services\ReconciliationQueryService;
use App\Modules\Reconciliation\Domain\Adapters\ProviderFileAdapterInterface;
use App\Modules\Reconciliation\Domain\Adapters\ProviderWebhookAuthenticatorInterface;
use App\Modules\Reconciliation\Domain\Adapters\SettlementAdapterInterface;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationModuleContract;
use App\Modules\Reconciliation\Infrastructure\Http\Middleware\ReconciliationWebhookAuth;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderFileAdapterInterface::class, static function ($app) {
            /** @var class-string $adapterClass */
            $adapterClass = config('reconciliation.provider_file_adapter', CsvProviderFileAdapter::class);
            return $app->make($adapterClass);
        });

        $this->app->bind(ProviderWebhookAuthenticatorInterface::class, HmacWebhookAuthenticator::class);

        $this->app->bind(SettlementAdapterInterface::class, static function ($app) {
            /** @var class-string $adapterClass */
            $adapterClass = config('reconciliation.settlement_adapter', NullSettlementAdapter::class);
            return $app->make($adapterClass);
        });

        $this->app->bind(ReconciliationMetricsInterface::class, ReconciliationMetricsService::class);
        $this->app->bind(ReconciliationModuleContract::class, ReconciliationQueryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/reconciliation.php'));

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('reconciliation.webhook_auth', ReconciliationWebhookAuth::class);
    }
}
