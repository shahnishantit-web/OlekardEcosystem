<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentBackendInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentRollbackRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\DeploymentVersionRepositoryInterface;
use App\Modules\Ops\Domain\Exceptions\DeploymentVersionNotFoundException;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use App\Modules\Ops\Infrastructure\Models\DeploymentRollbackModel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

final class DeploymentService
{
    public function __construct(
        private readonly DeploymentVersionRepositoryInterface $versions,
        private readonly DeploymentRollbackRepositoryInterface $rollbacks,
        private readonly DeploymentBackendInterface $backend,
        private readonly AuditService $audit,
        private readonly AlertRuleRepositoryInterface $alertRules,
        private readonly AlertDispatchService $alertDispatch,
    ) {}

    public function rollback(
        string $idempotencyKey,
        string $toVersion,
        ?string $reason,
        string $actorId,
        string $correlationId,
    ): DeploymentRollbackModel {
        $existing = $this->rollbacks->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $inProgress = $this->rollbacks->findInProgress();
        if ($inProgress !== null) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'A rollback is already in progress.',
                    'rollback_id' => $inProgress->id,
                ], 409)
            );
        }

        $current = $this->versions->current();
        if ($current === null) {
            throw new \RuntimeException('No current deployment version found.');
        }

        $target = $this->versions->findByVersion($toVersion);
        if ($target === null) {
            throw new DeploymentVersionNotFoundException($toVersion);
        }

        $rollback = $this->rollbacks->create(
            idempotencyKey: $idempotencyKey,
            fromVersion:    $current->version,
            toVersion:      $toVersion,
            initiatedBy:    $actorId,
            reason:         $reason,
        );

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.deployment.rollback_initiated',
                actorType:     AuditActorType::Admin,
                action:        'initiate_rollback',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withEntity('DeploymentRollback', $rollback->id)
            ->withAfterState(['from' => $current->version, 'to' => $toVersion])
        );

        try {
            $this->backend->rollback($current->version, $toVersion);
            $completed = $this->rollbacks->markCompleted($rollback->id);

            $this->audit->record(
                AuditEntry::make(
                    category:      AuditEventCategory::Admin,
                    eventType:     'ops.deployment.rollback_completed',
                    actorType:     AuditActorType::Admin,
                    action:        'complete_rollback',
                    outcome:       AuditOutcome::Success,
                    correlationId: $correlationId,
                )
                ->withActor($actorId)
                ->withEntity('DeploymentRollback', $rollback->id)
            );

            return $completed;
        } catch (\Throwable $e) {
            $failed = $this->rollbacks->markFailed($rollback->id, $e->getMessage());
            Log::error('Deployment rollback failed', ['rollback_id' => $rollback->id, 'error' => $e->getMessage()]);

            $this->fireRollbackFailureAlert($current->version, $toVersion, $e->getMessage());

            return $failed;
        }
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<\App\Modules\Ops\Infrastructure\Models\DeploymentVersionModel> */
    public function listVersions(int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->versions->paginate($perPage);
    }

    /** @return \Illuminate\Support\Collection<int, DeploymentRollbackModel> */
    public function recentRollbacks(int $limit = 20): \Illuminate\Support\Collection
    {
        return $this->rollbacks->recent($limit);
    }

    private function fireRollbackFailureAlert(string $fromVersion, string $toVersion, string $errorMessage): void
    {
        try {
            $rules = $this->alertRules->allEnabled();

            $matchingRule = $rules->first(fn (AlertRuleModel $r) =>
                isset($r->metric_condition['event']) && $r->metric_condition['event'] === 'deployment.rollback.failed'
            );

            if ($matchingRule === null) {
                return;
            }

            $this->alertDispatch->fire(
                $matchingRule,
                "Rollback from {$fromVersion} to {$toVersion} failed: {$errorMessage}"
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch rollback failure alert', ['error' => $e->getMessage()]);
        }
    }
}
