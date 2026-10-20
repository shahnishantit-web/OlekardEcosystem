<?php

declare(strict_types=1);

namespace App\Modules\Ops\Providers;

use App\Modules\Ops\Application\Services\IntegrationHealthHistory;
use App\Modules\Ops\Domain\Contracts\AlertRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\BackupJobRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\BackupStorageAdapterInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentBackendInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentRollbackRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentVersionRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\HealthSnapshotRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\IntegrationHealthHistoryInterface;
use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\ProviderHealthCheckRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\ProviderStatusRepositoryInterface;
use App\Modules\Ops\Infrastructure\Adapters\LocalDiskBackupStorageAdapter;
use App\Modules\Ops\Infrastructure\Adapters\MockDeploymentBackend;
use App\Modules\Ops\Infrastructure\Repositories\EloquentAlertRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentAlertRuleRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentBackupJobRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentDeploymentRollbackRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentDeploymentVersionRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentHealthSnapshotRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentLogRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentMetricRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentProviderHealthCheckRepository;
use App\Modules\Ops\Infrastructure\Repositories\EloquentProviderStatusRepository;
use Illuminate\Support\ServiceProvider;

final class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IntegrationHealthHistoryInterface::class, IntegrationHealthHistory::class);
        $this->app->bind(LogRepositoryInterface::class, EloquentLogRepository::class);
        $this->app->bind(MetricRepositoryInterface::class, EloquentMetricRepository::class);
        $this->app->bind(HealthSnapshotRepositoryInterface::class, EloquentHealthSnapshotRepository::class);
        $this->app->bind(ProviderHealthCheckRepositoryInterface::class, EloquentProviderHealthCheckRepository::class);
        $this->app->bind(ProviderStatusRepositoryInterface::class, EloquentProviderStatusRepository::class);
        $this->app->bind(AlertRuleRepositoryInterface::class, EloquentAlertRuleRepository::class);
        $this->app->bind(AlertRepositoryInterface::class, EloquentAlertRepository::class);
        $this->app->bind(BackupJobRepositoryInterface::class, EloquentBackupJobRepository::class);
        $this->app->bind(DeploymentVersionRepositoryInterface::class, EloquentDeploymentVersionRepository::class);
        $this->app->bind(DeploymentRollbackRepositoryInterface::class, EloquentDeploymentRollbackRepository::class);

        $this->app->bind(BackupStorageAdapterInterface::class, static function ($app) {
            /** @var class-string $adapterClass */
            $adapterClass = config('ops.backup_storage_adapter', LocalDiskBackupStorageAdapter::class);

            return $app->make($adapterClass);
        });

        $this->app->bind(DeploymentBackendInterface::class, static function ($app) {
            /** @var class-string $backendClass */
            $backendClass = config('ops.deployment_backend', MockDeploymentBackend::class);

            return $app->make($backendClass);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/ops-probes.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-ops.php'));
    }
}
