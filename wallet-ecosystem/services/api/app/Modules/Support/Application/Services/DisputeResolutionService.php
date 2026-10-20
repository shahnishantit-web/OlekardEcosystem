<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Events\DisputeApprovedEvent;
use App\Modules\Support\Domain\Events\DisputePendingConfirmationEvent;
use App\Modules\Support\Domain\Events\DisputeRejectedEvent;
use App\Modules\Support\Domain\Exceptions\DisputeForbiddenException;
use App\Modules\Support\Domain\Exceptions\DisputeNotFoundException;
use App\Modules\Support\Domain\Exceptions\InvalidDisputeStatusTransitionException;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use Illuminate\Support\Facades\DB;

final class DisputeResolutionService
{
    public function __construct(
        private readonly EventPublisherInterface     $events,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function approveDispute(
        string $disputeId,
        string $approvedById,
        string $actorRole,
        string $resolutionNote,
        string $idempotencyKey,
        string $correlationId,
    ): DisputeModel {
        if ($existing = $this->idempotency->findCompletedResponse($idempotencyKey, $approvedById)) {
            return DisputeModel::findOrFail($existing['dispute_id']);
        }

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $approvedById,
            hash('sha256', "{$approvedById}:{$idempotencyKey}"),
            'PATCH',
            "/v1/disputes/{$disputeId}/approve",
            $correlationId,
        );

        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin') {
            throw new DisputeForbiddenException("Only admins may approve disputes.");
        }

        $target = DisputeStatus::PendingConfirmation;
        if (!$dispute->status->isTransitionableTo($target)) {
            throw new InvalidDisputeStatusTransitionException(
                "Cannot approve dispute from [{$dispute->status->value}]."
            );
        }

        $dispute = DB::connection('platform')->transaction(function () use (
            $dispute, $approvedById, $resolutionNote, $correlationId, $target
        ): DisputeModel {
            $dispute->status          = $target;
            $dispute->resolution_note = $resolutionNote;
            $dispute->approved_by     = $approvedById;
            $dispute->approved_at     = now();
            $dispute->save();

            $this->events->publish(new DisputeApprovedEvent(
                disputeId:      $dispute->id,
                approvedById:   $approvedById,
                resolutionNote: $resolutionNote,
                occurredAt:     now()->toIso8601String(),
                correlationId:  $correlationId,
            ));

            $this->events->publish(new DisputePendingConfirmationEvent(
                disputeId:     $dispute->id,
                initiatedById: $approvedById,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $dispute;
        });

        $this->idempotency->recordResponse($lock, 200, ['dispute_id' => $dispute->id]);

        return $dispute;
    }

    public function confirmApproval(
        string $disputeId,
        string $confirmedById,
        string $actorRole,
        string $idempotencyKey,
        string $correlationId,
    ): DisputeModel {
        if ($existing = $this->idempotency->findCompletedResponse($idempotencyKey, $confirmedById)) {
            return DisputeModel::findOrFail($existing['dispute_id']);
        }

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $confirmedById,
            hash('sha256', "{$confirmedById}:{$idempotencyKey}"),
            'PATCH',
            "/v1/disputes/{$disputeId}/confirm-approval",
            $correlationId,
        );

        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin') {
            throw new DisputeForbiddenException("Only admins may confirm dispute approvals.");
        }

        if ($dispute->approved_by !== null && $dispute->approved_by === $confirmedById) {
            throw new DisputeForbiddenException("The approver cannot also confirm the same dispute.");
        }

        $target = DisputeStatus::Approved;
        if (!$dispute->status->isTransitionableTo($target)) {
            throw new InvalidDisputeStatusTransitionException(
                "Cannot confirm approval from [{$dispute->status->value}]."
            );
        }

        $dispute = DB::connection('platform')->transaction(function () use (
            $dispute, $target
        ): DisputeModel {
            $dispute->status             = $target;
            $dispute->second_approved_at = now();
            $dispute->save();

            return $dispute;
        });

        $this->idempotency->recordResponse($lock, 200, ['dispute_id' => $dispute->id]);

        return $dispute;
    }

    public function rejectDispute(
        string $disputeId,
        string $rejectedById,
        string $actorRole,
        string $rejectionReason,
        string $idempotencyKey,
        string $correlationId,
    ): DisputeModel {
        if ($existing = $this->idempotency->findCompletedResponse($idempotencyKey, $rejectedById)) {
            return DisputeModel::findOrFail($existing['dispute_id']);
        }

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $rejectedById,
            hash('sha256', "{$rejectedById}:{$idempotencyKey}"),
            'PATCH',
            "/v1/disputes/{$disputeId}/reject",
            $correlationId,
        );

        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin') {
            throw new DisputeForbiddenException("Only admins may reject disputes.");
        }

        $target = DisputeStatus::Rejected;
        if (!$dispute->status->isTransitionableTo($target)) {
            throw new InvalidDisputeStatusTransitionException(
                "Cannot reject dispute from [{$dispute->status->value}]."
            );
        }

        $dispute = DB::connection('platform')->transaction(function () use (
            $dispute, $rejectedById, $rejectionReason, $correlationId, $target
        ): DisputeModel {
            $dispute->status           = $target;
            $dispute->rejection_reason = $rejectionReason;
            $dispute->rejected_at      = now();
            $dispute->save();

            $this->events->publish(new DisputeRejectedEvent(
                disputeId:       $dispute->id,
                rejectedById:    $rejectedById,
                rejectionReason: $rejectionReason,
                occurredAt:      now()->toIso8601String(),
                correlationId:   $correlationId,
            ));

            return $dispute;
        });

        $this->idempotency->recordResponse($lock, 200, ['dispute_id' => $dispute->id]);

        return $dispute;
    }
}
