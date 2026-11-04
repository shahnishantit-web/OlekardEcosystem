<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reads approval evidence written through the scoped Events approval workflow. */
final class DatabaseComplimentaryAllocationApproval implements ComplimentaryAllocationApprovalContract
{
    public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
    {
        $row = DB::connection('community_services')->table('community_services.event_complimentary_approvals as approval')
            ->join('community_services.event_draft_audit as audit', 'audit.id', '=', 'approval.provenance_audit_id')
            ->join('community_services.event_authority_assignments as assignment', 'assignment.id', '=', 'approval.approval_assignment_id')
            ->where('approval.organisation_id', $organisationId)->where('approval.event_id', $eventId)
            ->where('approval.category_id', $categoryId)->where('approval.status', 'approved')
            ->whereNull('approval.revoked_at')->where('audit.action', 'complimentary_approval_approved')
            ->where('audit.event_id', $eventId)->whereColumn('audit.actor_id', 'approval.approved_by_actor_id')
            ->where('assignment.scope_type', 'organisation')->where('assignment.scope_id', $organisationId)
            ->where('assignment.organisation_id', $organisationId)->whereColumn('assignment.actor_id', 'approval.approved_by_actor_id')
            ->where('assignment.permission_slug', 'events.complimentary.approve')
            ->whereColumn('assignment.granted_at', '<=', 'approval.approved_at')
            ->orderByDesc('approval.approval_version')
            ->first([
                'approval.id as approval_id', 'approval.approval_version', 'approval.organisation_id',
                'approval.event_id', 'approval.category_id', 'approval.approved_quota',
                'approval.requested_by_actor_id', 'approval.approved_by_actor_id', 'approval.valid_from',
                'approval.expires_at', 'approval.revoked_at', 'approval.provenance_audit_id',
                'approval.approval_assignment_id', 'approval.approved_at',
            ]);

        if ($row === null || ! Uuid::isValid((string) $row->approval_id)
            || ! Uuid::isValid((string) $row->provenance_audit_id)
            || ! Uuid::isValid((string) $row->approval_assignment_id)) {
            throw new HttpException(503, 'Authoritative complimentary allocation approval evidence is missing or unverifiable.');
        }

        return new ComplimentaryAllocationApprovalEvidence(
            approvalId: (string) $row->approval_id,
            version: (int) $row->approval_version,
            organisationId: (string) $row->organisation_id,
            eventId: (string) $row->event_id,
            categoryId: (string) $row->category_id,
            approvedQuota: (int) $row->approved_quota,
            requestedByActorId: (string) $row->requested_by_actor_id,
            approvedByActorId: (string) $row->approved_by_actor_id,
            validFrom: new \DateTimeImmutable((string) $row->valid_from),
            expiresAt: new \DateTimeImmutable((string) $row->expires_at),
            revokedAt: $row->revoked_at === null ? null : new \DateTimeImmutable((string) $row->revoked_at),
            provenance: 'events.event_draft_audit:'.$row->provenance_audit_id.';assignment:'.$row->approval_assignment_id,
        );
    }
}
