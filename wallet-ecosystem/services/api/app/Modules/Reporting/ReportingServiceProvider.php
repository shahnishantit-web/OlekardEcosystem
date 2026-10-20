<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Domain\Contracts\ReportingModuleContract;
use App\Modules\Reporting\Domain\Events\ReportJobCompletedEvent;
use App\Modules\Reporting\Domain\Events\ReportJobFailedEvent;
use App\Modules\Reporting\Infrastructure\Listeners\ReportJobCompletedListener;
use App\Modules\Reporting\Infrastructure\Listeners\ReportJobFailedListener;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportingModuleContract::class, ReportJobApplicationService::class);
        $this->app->singleton(ReportJobApplicationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/reporting.php'));

        if (config('filesystems.disks.reports.driver') === 'local') {
            Storage::disk('reports')->makeDirectory('');
        }

        Event::listen(ReportJobCompletedEvent::class, ReportJobCompletedListener::class);
        Event::listen(ReportJobFailedEvent::class, ReportJobFailedListener::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('reports:purge-exports')->dailyAt('03:00')->timezone('UTC');
            $schedule->command('reports:process-schedules')->everyMinute();
        });
    }
}
