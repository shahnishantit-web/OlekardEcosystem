<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeadLetterCleanupJob;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;

/**
 * E27 US4: Manually trigger dead-letter TTL cleanup.
 */
final class PurgeDeadLetterCommand extends Command
{
    protected $signature   = 'reliability:purge-dead-letter';
    protected $description = 'Purge expired pending dead-letter items whose TTL has passed';

    public function handle(): int
    {
        $correlationId = (string) Uuid::uuid7();

        $this->info("Purging expired dead-letter items (correlation_id={$correlationId})...");

        dispatch_sync(new DeadLetterCleanupJob(correlationId: $correlationId));

        $this->info('Purge complete.');

        return self::SUCCESS;
    }
}
