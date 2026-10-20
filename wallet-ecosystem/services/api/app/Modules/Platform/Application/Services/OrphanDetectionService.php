<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\OrphanDetectableInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * E27 T074 / FR-004: Registry-based orphan detection across financial modules.
 *
 * Iterates each registered OrphanDetectableInterface implementation, finds orphaned
 * transaction IDs, and optionally attempts recovery per transaction.
 */
final class OrphanDetectionService
{
    /** @param OrphanDetectableInterface[] $implementations */
    public function __construct(
        private readonly array $implementations,
    ) {}

    /**
     * @return array<array{module: string, detected: int, resolved: int, skipped: int}>
     */
    public function detectAll(bool $dryRun = false, ?Carbon $threshold = null): array
    {
        $effectiveThreshold = $threshold ?? now()->subMinutes(
            (int) config('reliability.orphan_threshold_minutes', 5)
        );

        $results = [];

        foreach ($this->implementations as $implementation) {
            $module  = get_class($implementation);
            $orphans = $implementation->findOrphanedTransactionIds($effectiveThreshold);
            $detected = count($orphans);
            $resolved = 0;
            $skipped  = 0;

            Log::info('platform.orphan_detection.module_scan', [
                'module'        => $module,
                'detected'      => $detected,
                'dry_run'       => $dryRun,
                'threshold'     => $effectiveThreshold->toIso8601String(),
                'correlation_id' => (string) Uuid::uuid7(),
            ]);

            if (!$dryRun) {
                foreach ($orphans as $txId) {
                    $correlationId = (string) Uuid::uuid7();

                    try {
                        $status = $implementation->recoverOrphanedTransaction($txId, $correlationId);

                        if ($status === 'recovered' || $status === 'reversed') {
                            $resolved++;
                        } else {
                            $skipped++;
                        }

                        Log::info('platform.orphan_detection.transaction_processed', [
                            'module'         => $module,
                            'transaction_id' => $txId,
                            'status'         => $status,
                            'correlation_id' => $correlationId,
                        ]);
                    } catch (\Throwable $e) {
                        $skipped++;
                        Log::error('platform.orphan_detection.recovery_failed', [
                            'module'         => $module,
                            'transaction_id' => $txId,
                            'error'          => $e->getMessage(),
                            'correlation_id' => $correlationId,
                        ]);
                    }
                }
            } else {
                $skipped = $detected;
            }

            $results[] = compact('module', 'detected', 'resolved', 'skipped');
        }

        return $results;
    }
}
