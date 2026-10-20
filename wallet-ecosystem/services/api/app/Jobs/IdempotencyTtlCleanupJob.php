<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Infrastructure\Persistence\IdempotencyKeyModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class IdempotencyTtlCleanupJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Do NOT delete status='processing' records — those are cleaned by stuck-processing recovery.
        $count = IdempotencyKeyModel::query()
            ->where('expires_at', '<', now())
            ->whereNot('status', 'processing')
            ->delete();

        Log::info('idempotency.ttl_cleanup.completed', ['deleted_count' => $count]);
    }
}
