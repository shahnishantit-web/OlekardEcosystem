<?php

declare(strict_types=1);

namespace App\Modules\Support\Providers;

use App\Modules\Support\Application\Services\DisputeEvidenceService;
use App\Modules\Support\Application\Services\DisputeInvestigationService;
use App\Modules\Support\Application\Services\DisputeRefundService;
use App\Modules\Support\Application\Services\DisputeResolutionService;
use App\Modules\Support\Application\Services\DisputeService;
use App\Modules\Support\Application\Services\SupportModuleService;
use App\Modules\Support\Application\Services\SupportTicketNoteService;
use App\Modules\Support\Application\Services\SupportTicketService;
use App\Modules\Support\Domain\Contracts\EvidenceStorageContract;
use App\Modules\Support\Domain\Contracts\SupportModuleContract;
use App\Modules\Support\Infrastructure\Storage\LaravelDiskEvidenceStorage;
use Illuminate\Support\ServiceProvider;

final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupportModuleContract::class, SupportModuleService::class);
        $this->app->bind(EvidenceStorageContract::class, LaravelDiskEvidenceStorage::class);

        $this->app->singleton(SupportTicketService::class);
        $this->app->singleton(SupportTicketNoteService::class);
        $this->app->singleton(DisputeService::class);
        $this->app->singleton(DisputeEvidenceService::class);
        $this->app->singleton(DisputeInvestigationService::class);
        $this->app->singleton(DisputeResolutionService::class);
        $this->app->singleton(DisputeRefundService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/support.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-support.php'));
        $this->mergeConfigFrom(base_path('config/support.php'), 'support');
    }
}
