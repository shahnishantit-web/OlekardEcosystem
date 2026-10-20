<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Jobs;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Qa\Application\QaExecutorRegistry;
use App\Modules\Qa\Domain\Enums\TestResultOutcome;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Domain\Events\QaRunFailedEvent;
use App\Modules\Qa\Domain\Events\QaRunSucceededEvent;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteQaTestRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    /** @var int[] */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('qa');
    }

    public function handle(QaExecutorRegistry $registry, OutboxEventPublisher $outbox): void
    {
        $run = TestRun::on('platform')
            ->with('results')
            ->findOrFail($this->runId);

        Log::info('qa.job.started', ['run_id' => $this->runId, 'category' => $run->category->value]);

        // Mark as running
        $run->status     = TestRunStatus::Running;
        $run->started_at = now();
        $run->save();

        try {
            $executor = $registry->resolve($run->category);
            $executor->execute($run);
        } catch (Throwable $e) {
            Log::error('qa.job.executor.exception', [
                'run_id'    => $this->runId,
                'exception' => $e->getMessage(),
            ]);
        }

        // Finalize run status based on result outcomes
        $run->refresh();
        $results   = $run->results()->get();
        $total     = $results->count();
        $hasFailed = $results->contains(
            fn ($r) => in_array($r->outcome->value, [
                TestResultOutcome::Failed->value,
                TestResultOutcome::Error->value,
            ], true)
        );

        $finalStatus = $hasFailed ? TestRunStatus::Failed : TestRunStatus::Completed;

        DB::connection('platform')->transaction(function () use ($run, $finalStatus, $outbox, $total, $hasFailed): void {
            $run->status       = $finalStatus;
            $run->completed_at = now();
            $run->save();

            $passed  = $run->results()->where('outcome', TestResultOutcome::Passed->value)->count();
            $failed  = $total - $passed;
            $payload = [
                'run_id'         => $run->id,
                'category'       => $run->category,
                'correlationId'  => $run->correlation_id,
                'occurredAt'     => now()->toIso8601String(),
            ];

            if ($hasFailed) {
                $outbox->publish(new QaRunFailedEvent(
                    runId:         $run->id,
                    category:      $run->category,
                    failedCount:   $failed,
                    totalCount:    $total,
                    correlationId: $run->correlation_id,
                    occurredAt:    now()->toIso8601String(),
                ));
            } else {
                $outbox->publish(new QaRunSucceededEvent(
                    runId:         $run->id,
                    category:      $run->category,
                    passedCount:   $passed,
                    totalCount:    $total,
                    correlationId: $run->correlation_id,
                    occurredAt:    now()->toIso8601String(),
                ));
            }
        });

        Log::info('qa.job.completed', [
            'run_id'   => $this->runId,
            'status'   => $finalStatus->value,
            'total'    => $total,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('qa.job.failed', [
            'run_id'    => $this->runId,
            'exception' => $exception->getMessage(),
        ]);

        $run = TestRun::on('platform')->find($this->runId);
        if ($run !== null && !$run->status->isTerminal()) {
            $run->status       = TestRunStatus::Failed;
            $run->completed_at = now();
            $run->save();
        }
    }
}
