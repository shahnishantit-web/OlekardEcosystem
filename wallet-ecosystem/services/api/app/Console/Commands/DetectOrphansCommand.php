<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Application\Services\OrphanDetectionService;
use Illuminate\Console\Command;

/**
 * E27 US3 (FR-004): Manually trigger orphan detection across all financial transaction types.
 * Delegates fully to OrphanDetectionService (T074).
 */
final class DetectOrphansCommand extends Command
{
    protected $signature   = 'reliability:detect-orphans {--dry-run : Report what would be recovered without writing}';
    protected $description = 'Detect and recover interrupted (orphaned) pending financial transactions';

    public function handle(OrphanDetectionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] Orphan detection running in report-only mode — no state changes will be made.');
        } else {
            $this->info('Starting orphan detection and recovery...');
        }

        $results = $service->detectAll(dryRun: $dryRun);

        if (empty($results)) {
            $this->line('No modules registered for orphan detection.');
            return self::SUCCESS;
        }

        $rows = array_map(fn (array $r) => [
            class_basename($r['module']),
            $r['detected'],
            $dryRun ? 'N/A (dry-run)' : $r['resolved'],
            $dryRun ? $r['detected'] : $r['skipped'],
        ], $results);

        $this->table(['Module', 'Detected', 'Resolved', 'Skipped'], $rows);

        $total = array_sum(array_column($results, 'detected'));
        $this->info("Orphan detection scan complete. Total orphans detected: {$total}.");

        return self::SUCCESS;
    }
}
