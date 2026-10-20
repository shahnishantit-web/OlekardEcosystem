<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxDispatcherJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('outbox');
    }

    public function handle(EventBusDriverInterface $bus, AuditServiceInterface $audit): void
    {
        $events = DB::connection('platform')->transaction(function () {
            /** @var OutboxEventModel[] $rows */
            $rows = OutboxEventModel::query()
                ->whereIn('status', ['pending', 'failed'])
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')
                      ->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('created_at')
                ->limit(100)
                ->lockForUpdate()
                ->skip(0) // SKIP LOCKED not available via Eloquent; use raw for that
                ->get();

            $ids = $rows->pluck('id')->all();
            if (! empty($ids)) {
                OutboxEventModel::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status'                => 'processing',
                        'processing_started_at' => now(),
                    ]);
            }

            return $rows;
        });

        foreach ($events as $event) {
            try {
                $bus->dispatch($event);

                $event->update([
                    'status'       => 'delivered',
                    'delivered_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $attempts    = $event->attempts + 1;
                $maxAttempts = $event->max_attempts ?? 5;
                $backoff     = (int) min(3600, 60 * (2 ** ($attempts - 1)));

                if ($attempts >= $maxAttempts) {
                    $event->update([
                        'status'   => 'dead_letter',
                        'attempts' => $attempts,
                    ]);

                    $audit->record(new AuditEventData(
                        action: 'platform.outbox.dead-lettered',
                        actorType: 'system',
                        resourceType: 'OutboxEvent',
                        correlationId: $event->correlation_id ?? '',
                        outcome: 'failure',
                        resourceId: $event->id,
                        metadata: [
                            'event_name' => $event->event_name,
                            'attempts'   => $attempts,
                            'last_error' => $e->getMessage(),
                        ],
                    ));
                } else {
                    $event->update([
                        'status'          => 'failed',
                        'attempts'        => $attempts,
                        'next_attempt_at' => now()->addSeconds($backoff),
                    ]);
                }

                Log::error('outbox.dispatch_failed', [
                    'event_id'       => $event->id,
                    'event_name'     => $event->event_name,
                    'attempt'        => $attempts,
                    'correlation_id' => $event->correlation_id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }
}
