<?php

declare(strict_types=1);

namespace App\Modules\Agent\Providers;

use App\Modules\Agent\Domain\Contracts\AgentDocumentStorageContract;
use App\Modules\Agent\Domain\Contracts\AgentKycProviderContract;
use App\Modules\Agent\Domain\Contracts\VirusScannerContract;
use App\Modules\Agent\Infrastructure\Stubs\LaravelDiskAgentDocumentStorage;
use App\Modules\Agent\Infrastructure\Stubs\NullVirusScanner;
use App\Modules\Agent\Infrastructure\Stubs\StubAgentKycProvider;
use Illuminate\Support\ServiceProvider;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentKycProviderContract::class, StubAgentKycProvider::class);
        $this->app->bind(AgentDocumentStorageContract::class, LaravelDiskAgentDocumentStorage::class);
        $this->app->bind(VirusScannerContract::class, NullVirusScanner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/agent.php'));
    }
}
