<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\Services\PayoutQualificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PayoutQualificationJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60];

    public function __construct(private readonly string $statementId) {}

    public function uniqueId(): string
    {
        return $this->statementId;
    }

    public function handle(PayoutQualificationService $service): void
    {
        Log::info('PayoutQualificationJob.start', ['statement_id' => $this->statementId]);

        $summary = $service->computeAndPublish($this->statementId);

        Log::info('PayoutQualificationJob.done', [
            'statement_id'        => $this->statementId,
            'net_qualified_minor' => $summary->netQualifiedMinor,
            'version_hash'        => $summary->versionHash,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PayoutQualificationJob.failed', [
            'statement_id' => $this->statementId,
            'error'        => $e->getMessage(),
        ]);
    }
}
