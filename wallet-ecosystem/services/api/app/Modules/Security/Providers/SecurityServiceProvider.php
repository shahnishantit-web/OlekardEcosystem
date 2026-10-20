<?php

declare(strict_types=1);

namespace App\Modules\Security\Providers;

use App\Modules\Platform\Infrastructure\Persistence\SecurityAuditEventSource;
use App\Modules\Security\Application\Services\SecurityAuditWriter;
use App\Modules\Security\Application\Services\SecurityEventService;
use App\Modules\Security\Domain\Contracts\VulnerabilityFindingRepositoryInterface;
use App\Modules\Security\Infrastructure\Repositories\EloquentVulnerabilityFindingRepository;
use Illuminate\Support\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SecurityEventService::class, fn ($app) => new SecurityEventService([$app->make(SecurityAuditEventSource::class), $app->make(\App\Modules\Audit\Infrastructure\Repositories\SecurityAuditEventSource::class)], $app->make(SecurityAuditWriter::class)));
        $this->app->bind(
            VulnerabilityFindingRepositoryInterface::class,
            EloquentVulnerabilityFindingRepository::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/security.php'));
    }
}
