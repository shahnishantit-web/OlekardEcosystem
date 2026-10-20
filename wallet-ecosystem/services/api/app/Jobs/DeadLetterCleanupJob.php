<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * E27 US4: Purges expired pending dead-letter items (TTL enforced via expires_at).
 * Replayed and dismissed items are retained for audit purposes.
 */
final class DeadLetterCleanupJob implements ShouldQueue
{
    use Queueable;
    use HasCorrelationId;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(string $correlationId = '')
    {
        $this->correlationId = $correlationId ?: (string) Uuid::uuid7();
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->injectCorrelationContext();

        $deleted = DeadLetterItemModel::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->delete();

        Log::info('dead_letter.cleanup.completed', [
            'purged_count'   => $deleted,
            'correlation_id' => $this->correlationId,
        ]);
    }
}
