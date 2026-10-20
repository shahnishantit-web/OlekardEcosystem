<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Jobs;

use App\Modules\Reconciliation\Application\Services\ReconciliationMatchingService;
use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessReconciliationBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue('reconciliation');
    }

    public function handle(ReconciliationMatchingService $matchingService): void
    {
        Log::info('reconciliation.job.process_batch.started', ['batch_id' => $this->batchId]);

        $matchingService->matchBatch($this->batchId);

        Log::info('reconciliation.job.process_batch.completed', ['batch_id' => $this->batchId]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('reconciliation.job.process_batch.failed', [
            'batch_id'  => $this->batchId,
            'exception' => $exception->getMessage(),
        ]);

        $batch = ReconciliationBatch::on('reconciliation')->find($this->batchId);

        if ($batch !== null) {
            $batch->update([
                'status'   => BatchStatus::Failed->value,
                'metadata' => array_merge($batch->metadata ?? [], [
                    'error'      => $exception->getMessage(),
                    'failed_at'  => now()->toIso8601String(),
                ]),
            ]);
        }
    }
}
