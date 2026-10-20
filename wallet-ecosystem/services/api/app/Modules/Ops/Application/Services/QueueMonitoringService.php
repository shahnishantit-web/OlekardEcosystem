<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use Illuminate\Support\Facades\Queue;
use Throwable;

final class QueueMonitoringService
{
    private const QUEUES = ['default', 'high', 'low', 'notifications', 'reports'];

    public function __construct(
        private readonly MetricsEmitter $emitter,
    ) {}

    public function collect(): void
    {
        foreach (self::QUEUES as $queueName) {
            try {
                $depth = Queue::size($queueName);
                $this->emitter->emit("queue.{$queueName}", 'queue.depth', (float) $depth, 'count');
            } catch (Throwable) {
                // Queue may not exist; skip silently.
            }
        }

        // Horizon failed job count via Redis key if Horizon is running.
        try {
            $failed = \Illuminate\Support\Facades\Redis::connection('default')
                ->command('llen', ['failed_jobs']) ?? 0;
            $this->emitter->emit('queue.default', 'queue.failed_count', (float) $failed, 'count');
        } catch (Throwable) {
            $this->emitter->emit('queue.default', 'queue.failed_count', 0.0, 'count');
        }
    }
}
