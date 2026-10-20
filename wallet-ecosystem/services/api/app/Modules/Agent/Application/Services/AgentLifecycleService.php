<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Events\AgentApprovedEvent;
use App\Modules\Agent\Domain\Events\AgentRejectedEvent;
use App\Modules\Agent\Domain\Events\AgentReactivatedEvent;
use App\Modules\Agent\Domain\Events\AgentSuspendedEvent;
use App\Modules\Agent\Domain\Exceptions\AgentNotActiveException;
use App\Modules\Agent\Domain\Exceptions\AgentNotSuspendedException;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotApprovableException;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotEditableException;
use App\Modules\Agent\Domain\Exceptions\MakerCheckerViolationException;
use App\Modules\Agent\Infrastructure\Models\AgentApplicationModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentLifecycleService
{
    public function __construct(
        private readonly IdempotencyServiceInterface  $idempotency,
        private readonly EventPublisherInterface      $events,
        private readonly ConfigurationServiceInterface $config,
        private readonly AgentRoleAssignmentService   $roleAssignment,
    ) {}

    public function approve(
        string  $applicationId,
        string  $adminActorId,
        ?string $notes,
        string  $idempotencyKey,
        string  $correlationId,
    ): AgentModel {
        $storeKey = "agent_approve:{$applicationId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $adminActorId);
        if ($cached !== null) {
            $agentId = $cached['body']['agent_id'] ?? null;
            if ($agentId !== null) {
                $existing = AgentModel::on('platform')->find($agentId);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $adminActorId,
            hash('sha256', $storeKey),
            'POST',
            "/v1/agent-applications/{$applicationId}/approve",
            $correlationId,
        );

        try {
            return DB::connection('platform')->transaction(
                function () use ($applicationId, $adminActorId, $notes, $correlationId, $lock): AgentModel {
                    $application = DB::connection('platform')
                        ->table('agent.agent_applications')
                        ->lockForUpdate()
                        ->where('id', $applicationId)
                        ->whereIn('status', [
                            ApplicationStatus::KycPassed->value,
                            ApplicationStatus::KycFailed->value,
                            ApplicationStatus::UnderReview->value,
                        ])
                        ->first();

                    if ($application === null) {
                        throw new ApplicationNotApprovableException();
                    }

                    $requireMakerChecker = (bool) $this->config->getOrDefault('agent.approval.require_maker_checker', true);

                    if ($requireMakerChecker && $application->last_updated_by === $adminActorId) {
                        throw new MakerCheckerViolationException();
                    }

                    $autoApprove = (bool) $this->config->getOrDefault('agent.approval.auto_approve', false);

                    if (!$autoApprove && in_array($application->status, [
                        ApplicationStatus::KycPassed->value,
                        ApplicationStatus::KycFailed->value,
                    ], true)) {
                        DB::connection('platform')
                            ->table('agent.agent_applications')
                            ->where('id', $applicationId)
                            ->update(['status' => ApplicationStatus::UnderReview->value]);
                    }

                    DB::connection('platform')
                        ->table('agent.agent_applications')
                        ->where('id', $applicationId)
                        ->update([
                            'status'          => ApplicationStatus::Approved->value,
                            'last_updated_by' => $adminActorId,
                            'approved_at'     => now(),
                            'updated_at'      => now(),
                        ]);

                    $agentId = (string) Uuid::uuid7();
                    $now     = now();

                    DB::connection('platform')->table('agent.agents')->insert([
                        'id'             => $agentId,
                        'application_id' => $applicationId,
                        'user_id'        => $application->user_id,
                        'agent_type'     => $application->agent_type,
                        'status'         => AgentStatus::Active->value,
                        'approved_by'    => $adminActorId,
                        'approved_at'    => $now,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);

                    DB::connection('platform')->table('agent.agent_status_history')->insert([
                        'id'              => (string) Uuid::uuid7(),
                        'agent_id'        => $agentId,
                        'previous_status' => null,
                        'new_status'      => AgentStatus::Active->value,
                        'actor_id'        => $adminActorId,
                        'actor_type'      => 'admin',
                        'reason'          => $notes,
                        'occurred_at'     => $now,
                    ]);

                    $this->roleAssignment->assignAgentRole($application->user_id, $adminActorId);

                    $this->events->publish(new AgentApprovedEvent(
                        agentId:       $agentId,
                        applicationId: $applicationId,
                        userId:        $application->user_id,
                        approvedBy:    $adminActorId,
                        occurredAt:    $now->toIso8601String(),
                        correlationId: $correlationId,
                    ));

                    $this->idempotency->recordResponse($lock, 200, ['agent_id' => $agentId]);

                    return AgentModel::on('platform')->findOrFail($agentId);
                }
            );
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    public function reject(
        string  $applicationId,
        string  $adminActorId,
        string  $reason,
        string  $idempotencyKey,
        string  $correlationId,
    ): AgentApplicationModel {
        $storeKey = "agent_reject:{$applicationId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $adminActorId);
        if ($cached !== null) {
            return AgentApplicationModel::on('platform')->findOrFail($applicationId);
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $adminActorId,
            hash('sha256', $storeKey),
            'POST',
            "/v1/agent-applications/{$applicationId}/reject",
            $correlationId,
        );

        try {
            return DB::connection('platform')->transaction(
                function () use ($applicationId, $adminActorId, $reason, $correlationId, $lock): AgentApplicationModel {
                    $application = DB::connection('platform')
                        ->table('agent.agent_applications')
                        ->lockForUpdate()
                        ->where('id', $applicationId)
                        ->whereIn('status', [
                            ApplicationStatus::KycPassed->value,
                            ApplicationStatus::KycFailed->value,
                            ApplicationStatus::UnderReview->value,
                        ])
                        ->first();

                    if ($application === null) {
                        throw new ApplicationNotApprovableException();
                    }

                    DB::connection('platform')
                        ->table('agent.agent_applications')
                        ->where('id', $applicationId)
                        ->update([
                            'status'           => ApplicationStatus::Rejected->value,
                            'rejection_reason' => $reason,
                            'last_updated_by'  => $adminActorId,
                            'rejected_at'      => now(),
                            'updated_at'       => now(),
                        ]);

                    $this->events->publish(new AgentRejectedEvent(
                        applicationId: $applicationId,
                        rejectedBy:    $adminActorId,
                        reason:        $reason,
                        occurredAt:    now()->toIso8601String(),
                        correlationId: $correlationId,
                    ));

                    $this->idempotency->recordResponse($lock, 200, ['application_id' => $applicationId]);

                    return AgentApplicationModel::on('platform')->findOrFail($applicationId);
                }
            );
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    public function suspend(
        string  $agentId,
        string  $adminActorId,
        string  $reason,
        string  $idempotencyKey,
        string  $correlationId,
    ): AgentModel {
        $storeKey = "agent_suspend:{$agentId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $adminActorId);
        if ($cached !== null) {
            return AgentModel::on('platform')->findOrFail($agentId);
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $adminActorId,
            hash('sha256', $storeKey),
            'POST',
            "/v1/agents/{$agentId}/suspend",
            $correlationId,
        );

        try {
            return DB::connection('platform')->transaction(
                function () use ($agentId, $adminActorId, $reason, $correlationId, $lock): AgentModel {
                    $agent = DB::connection('platform')
                        ->table('agent.agents')
                        ->lockForUpdate()
                        ->where('id', $agentId)
                        ->first();

                    if ($agent === null || $agent->status !== AgentStatus::Active->value) {
                        throw new AgentNotActiveException();
                    }

                    $now = now();

                    DB::connection('platform')
                        ->table('agent.agents')
                        ->where('id', $agentId)
                        ->update([
                            'status'           => AgentStatus::Suspended->value,
                            'suspended_by'     => $adminActorId,
                            'suspension_reason' => $reason,
                            'suspended_at'     => $now,
                            'updated_at'       => $now,
                        ]);

                    DB::connection('platform')->table('agent.agent_status_history')->insert([
                        'id'              => (string) Uuid::uuid7(),
                        'agent_id'        => $agentId,
                        'previous_status' => AgentStatus::Active->value,
                        'new_status'      => AgentStatus::Suspended->value,
                        'actor_id'        => $adminActorId,
                        'actor_type'      => 'admin',
                        'reason'          => $reason,
                        'occurred_at'     => $now,
                    ]);

                    $this->events->publish(new AgentSuspendedEvent(
                        agentId:      $agentId,
                        suspendedBy:  $adminActorId,
                        reason:       $reason,
                        occurredAt:   $now->toIso8601String(),
                        correlationId: $correlationId,
                    ));

                    $this->idempotency->recordResponse($lock, 200, ['agent_id' => $agentId]);

                    return AgentModel::on('platform')->findOrFail($agentId);
                }
            );
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    public function reactivate(
        string  $agentId,
        string  $adminActorId,
        string  $idempotencyKey,
        string  $correlationId,
    ): AgentModel {
        $storeKey = "agent_reactivate:{$agentId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $adminActorId);
        if ($cached !== null) {
            return AgentModel::on('platform')->findOrFail($agentId);
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $adminActorId,
            hash('sha256', $storeKey),
            'POST',
            "/v1/agents/{$agentId}/reactivate",
            $correlationId,
        );

        try {
            return DB::connection('platform')->transaction(
                function () use ($agentId, $adminActorId, $correlationId, $lock): AgentModel {
                    $agent = DB::connection('platform')
                        ->table('agent.agents')
                        ->lockForUpdate()
                        ->where('id', $agentId)
                        ->first();

                    if ($agent === null || $agent->status !== AgentStatus::Suspended->value) {
                        throw new AgentNotSuspendedException();
                    }

                    $now = now();

                    DB::connection('platform')
                        ->table('agent.agents')
                        ->where('id', $agentId)
                        ->update([
                            'status'           => AgentStatus::Active->value,
                            'reactivated_by'   => $adminActorId,
                            'reactivated_at'   => $now,
                            'suspended_by'     => null,
                            'suspension_reason' => null,
                            'suspended_at'     => null,
                            'updated_at'       => $now,
                        ]);

                    DB::connection('platform')->table('agent.agent_status_history')->insert([
                        'id'              => (string) Uuid::uuid7(),
                        'agent_id'        => $agentId,
                        'previous_status' => AgentStatus::Suspended->value,
                        'new_status'      => AgentStatus::Active->value,
                        'actor_id'        => $adminActorId,
                        'actor_type'      => 'admin',
                        'reason'          => null,
                        'occurred_at'     => $now,
                    ]);

                    $this->events->publish(new AgentReactivatedEvent(
                        agentId:      $agentId,
                        reactivatedBy: $adminActorId,
                        occurredAt:   $now->toIso8601String(),
                        correlationId: $correlationId,
                    ));

                    $this->idempotency->recordResponse($lock, 200, ['agent_id' => $agentId]);

                    return AgentModel::on('platform')->findOrFail($agentId);
                }
            );
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }
}
