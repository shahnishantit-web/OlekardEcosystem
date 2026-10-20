<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Jobs;

use App\Modules\Ops\Application\Services\QueueMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class QueueMetricsCollectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(QueueMonitoringService $service): void
    {
        $service->collect();
    }
}
