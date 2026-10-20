<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class OutboxStuckProcessingCleanupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ConfigurationService $config)
    {
        $this->onQueue('outbox');
    }

    public function handle(): void
    {
        $timeoutSeconds = $this->config->get('events.outbox.processing_timeout_seconds');

        if ($timeoutSeconds === null) {
            // Safety fallback — never disable the stuck-processing recovery mechanism (FR-182)
            $timeoutSeconds = 300;
            Log::warning('outbox.stuck_processing_cleanup.fallback_timeout', [
                'message' => 'events.outbox.processing_timeout_seconds is not configured; using 300s safety fallback — set this value for explicit operational control',
            ]);
        }

        $cutoff = now()->subSeconds((int) $timeoutSeconds);

        $stuck = OutboxEventModel::query()
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $event) {
            $event->update([
                'status'                => 'pending',
                'processing_started_at' => null,
                // attempts is intentionally NOT reset — recovery counts as an attempt
            ]);

            Log::warning('outbox.stuck_processing_reset', [
                'event_id'       => $event->id,
                'aggregate_id'   => $event->aggregate_id,
                'event_name'     => $event->event_name,
                'stuck_since'    => $event->processing_started_at,
                'correlation_id' => $event->correlation_id,
            ]);
        }
    }
}
