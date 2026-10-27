<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\Services\StatementMatchingService;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class StatementMatchingJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(public readonly string $statementId) {}

    public function uniqueId(): string
    {
        return $this->statementId;
    }

    public function handle(StatementMatchingService $matchingService, ProviderStatementRepositoryInterface $repository): void
    {
        $repository->updateStatus($this->statementId, ProviderStatementStatus::Matching);

        Log::info('payment.statement.matching_started', ['statement_id' => $this->statementId]);

        $matchingService->matchStatement($this->statementId);
    }
}
