<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Application\Services\ComplianceReportService;
use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Audit\Application\Services\RetentionPolicyService;
use App\Modules\Audit\Application\Services\SuspiciousActivityReportService;
use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Infrastructure\Listeners\AmlRestrictionChangedListener;
use App\Modules\Audit\Infrastructure\Listeners\AmlRiskLevelChangedListener;
use App\Modules\Audit\Infrastructure\Listeners\KycStatusChangedListener;
use App\Modules\Kyc\Domain\Events\KycStatusChangedEvent;
use App\Modules\Risk\Domain\Events\AmlRestrictionAppliedEvent;
use App\Modules\Risk\Domain\Events\AmlRestrictionLiftedEvent;
use App\Modules\Risk\Domain\Events\AmlRiskLevelChangedEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditModuleContract::class, AuditService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(ComplianceStatusService::class);
        $this->app->singleton(ComplianceReportService::class);
        $this->app->singleton(SuspiciousActivityReportService::class);
        $this->app->singleton(RetentionPolicyService::class);
    }

    public function boot(): void
    {
        if (!app()->environment('testing') && empty(config('audit.hmac_key'))) {
            throw new \RuntimeException('AUDIT_HMAC_KEY must be set and non-empty — set AUDIT_HMAC_KEY in your .env file');
        }

        $this->loadMigrationsFrom(__DIR__.'/Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/audit.php'));

        Event::listen(KycStatusChangedEvent::class, KycStatusChangedListener::class);
        Event::listen(AmlRiskLevelChangedEvent::class, AmlRiskLevelChangedListener::class);
        Event::listen(AmlRestrictionAppliedEvent::class, [AmlRestrictionChangedListener::class, 'handleApplied']);
        Event::listen(AmlRestrictionLiftedEvent::class, [AmlRestrictionChangedListener::class, 'handleLifted']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:enforce-retention')->dailyAt('02:00')->timezone('UTC');
        });
    }
}
