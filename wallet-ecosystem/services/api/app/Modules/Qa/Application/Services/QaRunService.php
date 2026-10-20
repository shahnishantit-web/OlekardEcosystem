<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Services;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Qa\Application\Jobs\ExecuteQaTestRunJob;
use App\Modules\Qa\Domain\Enums\QaCategory;
use App\Modules\Qa\Domain\Enums\TestRunStatus;
use App\Modules\Qa\Domain\Events\QaRunRequestedEvent;
use App\Modules\Qa\Models\TestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class QaRunService
{
    public function __construct(
        private readonly OutboxEventPublisher $outbox,
    ) {}

    /**
     * Trigger a QA test run for the given category.
     *
     * @return array{0: TestRun, 1: bool} [run, isNew] — isNew=false means idempotent duplicate
     * @throws \App\Modules\Qa\Exceptions\QaCategoryAlreadyRunningException
     */
    public function triggerRun(
        QaCategory $category,
        string     $idempotencyKey,
        string     $actorId,
        string     $correlationId,
    ): array {
        // Idempotency check: return existing run if key already used
        $existing = TestRun::on('platform')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            Log::info('qa.run.idempotent', [
                'run_id'          => $existing->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [$existing, false];
        }

        // Concurrency guard: reject if another run for this category is currently in progress
        $running = TestRun::on('platform')
            ->where('category', $category->value)
            ->where('status', TestRunStatus::Running->value)
            ->exists();

        if ($running) {
            throw new \App\Modules\Qa\Exceptions\QaCategoryAlreadyRunningException($category);
        }

        // Create TestRun and emit outbox event within the same transaction
        $run = DB::connection('platform')->transaction(function () use (
            $category, $idempotencyKey, $actorId, $correlationId
        ): TestRun {
            $run = new TestRun();
            $run->category        = $category;
            $run->triggered_by    = $actorId;
            $run->status          = TestRunStatus::Pending;
            $run->idempotency_key = $idempotencyKey;
            $run->correlation_id  = $correlationId;
            $run->created_at      = now();
            $run->save();

            $this->outbox->publish(new QaRunRequestedEvent(
                runId:         $run->id,
                category:      $category,
                actorId:       $actorId,
                correlationId: $correlationId,
                occurredAt:    now()->toIso8601String(),
            ));

            return $run;
        });

        // Dispatch the async executor job after transaction commits
        ExecuteQaTestRunJob::dispatch($run->id);

        Log::info('qa.run.triggered', [
            'run_id'   => $run->id,
            'category' => $category->value,
            'actor_id' => $actorId,
        ]);

        return [$run, true];
    }
}
