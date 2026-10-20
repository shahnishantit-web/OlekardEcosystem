<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Support\Domain\Enums\DisputeReasonCode;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Events\DisputeEscalatedEvent;
use App\Modules\Support\Domain\Events\DisputeInvestigationStartedEvent;
use App\Modules\Support\Domain\Events\DisputeReasonChangedEvent;
use App\Modules\Support\Domain\Exceptions\DisputeAlreadyEscalatedException;
use App\Modules\Support\Domain\Exceptions\DisputeForbiddenException;
use App\Modules\Support\Domain\Exceptions\DisputeNotFoundException;
use App\Modules\Support\Domain\Exceptions\InvalidDisputeStatusTransitionException;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use Illuminate\Support\Facades\DB;

final class DisputeInvestigationService
{
    public function __construct(
        private readonly EventPublisherInterface $events,
        private readonly IdentityModuleContract  $identity,
    ) {}

    public function setReason(
        string  $disputeId,
        string  $reasonCode,
        ?string $description,
        string  $actorId,
        string  $actorRole,
        string  $correlationId,
    ): DisputeModel {
        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin' && $dispute->reporter_id !== $actorId) {
            throw new DisputeForbiddenException("Access denied to dispute [{$disputeId}].");
        }

        if ($dispute->status->isTerminal()) {
            throw new InvalidDisputeStatusTransitionException("Dispute [{$disputeId}] is in a terminal state.");
        }

        return DB::connection('platform')->transaction(function () use (
            $dispute, $reasonCode, $description, $actorId, $correlationId
        ): DisputeModel {
            $dispute->reason_code        = DisputeReasonCode::tryFrom($reasonCode);
            $dispute->reason_description = $description;
            $dispute->save();

            $this->events->publish(new DisputeReasonChangedEvent(
                disputeId:     $dispute->id,
                reason:        $reasonCode,
                description:   $description,
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $dispute;
        });
    }

    public function startInvestigation(
        string $disputeId,
        string $investigatorId,
        string $actorId,
        string $actorRole,
        string $correlationId,
    ): DisputeModel {
        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin') {
            throw new DisputeForbiddenException("Only admins may start an investigation.");
        }

        $target = DisputeStatus::UnderInvestigation;
        if (!$dispute->status->isTransitionableTo($target)) {
            throw new InvalidDisputeStatusTransitionException(
                "Cannot transition dispute from [{$dispute->status->value}] to [under_investigation]."
            );
        }

        return DB::connection('platform')->transaction(function () use (
            $dispute, $investigatorId, $correlationId, $target
        ): DisputeModel {
            $dispute->status                   = $target;
            $dispute->investigator_id          = $investigatorId;
            $dispute->investigation_started_at = now();
            $dispute->save();

            $this->events->publish(new DisputeInvestigationStartedEvent(
                disputeId:      $dispute->id,
                investigatorId: $investigatorId,
                occurredAt:     now()->toIso8601String(),
                correlationId:  $correlationId,
            ));

            return $dispute;
        });
    }

    public function escalate(
        string $disputeId,
        string $actorId,
        string $actorRole,
        string $reason,
        string $correlationId,
    ): DisputeModel {
        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        $isReporter      = $actorId === $dispute->reporter_id;
        $isInvestigator  = $this->identity->hasPermission($actorId, 'support.investigate');
        $isAdmin         = $actorRole === 'admin';

        if (!$isReporter && !$isInvestigator && !$isAdmin) {
            throw new DisputeForbiddenException(
                "Actor [{$actorId}] is not authorised to escalate dispute [{$disputeId}]."
            );
        }

        if ($dispute->escalated) {
            throw new DisputeAlreadyEscalatedException();
        }

        if ($dispute->status->isTerminal()) {
            throw new InvalidDisputeStatusTransitionException("Cannot escalate a terminal dispute.");
        }

        return DB::connection('platform')->transaction(function () use (
            $dispute, $actorId, $reason, $correlationId
        ): DisputeModel {
            $dispute->escalated          = true;
            $dispute->escalated_at       = now();
            $dispute->escalation_reason  = $reason;
            $dispute->save();

            $this->events->publish(new DisputeEscalatedEvent(
                disputeId:    $dispute->id,
                escalatedById: $actorId,
                reason:       $reason,
                occurredAt:   now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $dispute;
        });
    }
}
