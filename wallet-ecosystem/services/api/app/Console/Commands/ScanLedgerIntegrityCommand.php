<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\LedgerIntegrityJob;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;

final class ScanLedgerIntegrityCommand extends Command
{
    protected $signature = 'reliability:scan-ledger-integrity';
    protected $description = 'Run a ledger integrity scan once and report the outcome';

    public function handle(): int
    {
        $correlationId = (string) Uuid::uuid7();
        $this->info("Starting ledger integrity scan [correlation_id={$correlationId}]...");

        $job = new LedgerIntegrityJob(correlationId: $correlationId);
        dispatch_sync($job);

        $this->info('Ledger integrity scan complete. Check the latest snapshot via GET /v1/operations/ledger-integrity/snapshots.');

        return self::SUCCESS;
    }
}
