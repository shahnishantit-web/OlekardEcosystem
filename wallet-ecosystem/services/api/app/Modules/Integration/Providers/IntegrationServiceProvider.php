<?php

declare(strict_types=1);

namespace App\Modules\Integration\Providers;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Modules\Integration\Application\Services\ProviderHealthInventory;
use App\Modules\Integration\Domain\Contracts\ApiClientRepositoryInterface;
use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\IntegrationRepositoryInterface;
use App\Modules\Integration\Domain\Contracts\ProviderHealthInventoryInterface;
use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Domain\Events\WebhookEventDeadLetteredEvent;
use App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware;
use App\Modules\Integration\Infrastructure\Http\Middleware\DualRateLimitMiddleware;
use App\Modules\Integration\Infrastructure\Listeners\NotifyWebhookDeadLettered;
use App\Modules\Integration\Infrastructure\Providers\IntegrationRegistry;
use App\Modules\Integration\Infrastructure\Repositories\EloquentApiClientRepository;
use App\Modules\Integration\Infrastructure\Repositories\EloquentIntegrationRepository;
use App\Modules\Integration\Infrastructure\Repositories\EloquentWebhookEventRepository;
use App\Modules\Notification\Domain\Contracts\EmailChannelInterface;
use App\Modules\Notification\Domain\Contracts\SmsChannelInterface;
use App\Modules\Notification\Infrastructure\Channels\LogEmailChannel;
use App\Modules\Notification\Infrastructure\Channels\LogSmsChannel;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderHealthInventoryInterface::class, ProviderHealthInventory::class);
        $this->app->bind(
            ApiClientRepositoryInterface::class,
            EloquentApiClientRepository::class,
        );

        $this->app->bind(
            IntegrationRepositoryInterface::class,
            EloquentIntegrationRepository::class,
        );

        $this->app->bind(
            WebhookEventRepositoryInterface::class,
            EloquentWebhookEventRepository::class,
        );

        $this->app->singleton(IntegrationRegistryInterface::class, function ($app): IntegrationRegistry {
            return new IntegrationRegistry(
                $app->make(IntegrationRepositoryInterface::class),
                $app,
                $app->make(CacheRepository::class),
            );
        });

        // Re-bind SMS/Email channels to fall back to Log stubs when no active integration exists.
        // The Notification module binds them first in its ServiceProvider; we override here.
        $this->app->bind(SmsChannelInterface::class, function ($app): SmsChannelInterface {
            $registry = $app->make(IntegrationRegistryInterface::class);
            $abstract = 'integration.channel.sms';
            if ($app->bound($abstract)) {
                return $app->make($abstract);
            }

            return $app->make(LogSmsChannel::class);
        });

        $this->app->bind(EmailChannelInterface::class, function ($app): EmailChannelInterface {
            $registry = $app->make(IntegrationRegistryInterface::class);
            $abstract = 'integration.channel.email';
            if ($app->bound($abstract)) {
                return $app->make($abstract);
            }

            return $app->make(LogEmailChannel::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/integration.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-integration.php'));

        // T067: dead-letter notification listener.
        Event::listen(WebhookEventDeadLetteredEvent::class, NotifyWebhookDeadLettered::class);

        $this->registerMiddlewareAliases();
    }

    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        // Global CorrelationIdMiddleware already applied platform-wide; alias for explicitness on M2M routes.
        $router->aliasMiddleware(
            'integration.correlation-id',
            CorrelationIdMiddleware::class,
        );

        // Structured request/response logging — global middleware already covers all routes.
        $router->aliasMiddleware(
            'integration.request-log',
            RequestLoggingMiddleware::class,
        );

        // Dual rate-limit (per-client + per-IP sliding windows).
        $router->aliasMiddleware(
            'integration.rate-limit',
            DualRateLimitMiddleware::class,
        );

        // M2M API client authentication (Bearer base64(key:secret)).
        $router->aliasMiddleware(
            'integration.client-auth',
            ApiClientAuthMiddleware::class,
        );
    }
}
