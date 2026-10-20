<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Jobs;

use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class RetryWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $webhookEventId,
        public readonly int $attemptNumber,
        public readonly ?int $generation = null,
    ) {
        $this->onQueue('webhooks');
        // Exponential back-off: 2^attempt * 30 seconds, capped at 3600.
        $delaySeconds = min((int) (2 ** $attemptNumber) * 30, 3600);
        $this->delay($delaySeconds);
    }

    public function handle(): void
    {
        $db = DB::connection('platform');
        $key = 'integration-webhook:'.$this->webhookEventId;
        $db->select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$key]);
        try {
            $event = WebhookEventModel::find($this->webhookEventId);

            if ($event === null || $event->status !== WebhookEventStatus::Failed || $event->retry_count !== $this->attemptNumber || ($this->generation !== null && $event->processing_generation !== $this->generation)) {
                return;
            }

            $event->status = WebhookEventStatus::Processing;
            $event->save();

            ProcessWebhookEventJob::dispatch($this->webhookEventId, $event->processing_generation, $event->retry_count)->onQueue('webhooks');
        } finally {
            $db->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }
}
