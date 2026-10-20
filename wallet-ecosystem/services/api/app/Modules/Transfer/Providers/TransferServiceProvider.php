<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Providers;

use App\Modules\Transfer\Application\Contracts\TransferRiskService;
use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Console\ExpirePendingTransfersCommand;
use App\Modules\Transfer\Infrastructure\Stubs\TransferRiskServiceStub;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class TransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LookupTokenService::class, function (): LookupTokenService {
            $ttl = (int) $this->app->make(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class)
                ->getOrDefault('transfer.lookup_token_ttl_seconds', 300);

            return new LookupTokenService(ttlSeconds: $ttl);
        });

        $this->app->bind(TransferRiskService::class, TransferRiskServiceStub::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('transfer-lookup', static function (\Illuminate\Http\Request $request) {
            $limit = (int) app(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class)
                ->getOrDefault('transfer.lookup_rate_limit.per_minute', 10);
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)
                ->by((string) $request->attributes->get('identity.user_id', $request->ip()));
        });
        $this->loadMigrationsFrom(__DIR__ . '/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/transfer.php'));
        $this->commands([ExpirePendingTransfersCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('transfer:expire-pending')->everyMinute()->withoutOverlapping();
        });
    }
}
