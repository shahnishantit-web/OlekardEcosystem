<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Reviewed administrative registry. Evidence references are opaque; this service never certifies their contents. */
final class ReviewedOrganisationMembershipService implements OrganisationMembershipAuthority
{
    public function __construct(private readonly IdentityModuleContract $identity) {}

    private function db(): Connection
    {
        return DB::connection('identity');
    }

    public function assertActiveMembership(string $actorId, string $organisationId): void
    {
        $valid = $this->db()->table('identity.organisation_memberships as membership')
            ->join('identity.organisations as organisation', 'organisation.id', '=', 'membership.organisation_id')
            ->join('identity.users as actor', 'actor.id', '=', 'membership.user_id')
            ->where('membership.organisation_id', $organisationId)
            ->where('membership.user_id', $actorId)
            ->where('membership.status', 'active')
            ->where('organisation.status', 'active')
            ->where('actor.status', 'active')
            ->exists();
        if (! $valid) {
            abort(403, 'Active reviewed organisation membership is required.');
        }
    }

    /** @return array<string, mixed> */
    public function requestOrganisation(string $reference, string $name, string $evidence, string $actorId): array
    {
        $this->requireAdmin($actorId, 'organisation.registry.request');
        $id = (string) Uuid::uuid7();
        try {
            $this->db()->transaction(function () use ($id, $reference, $name, $evidence, $actorId): void {
                if ($this->db()->table('identity.organisations')->where('registry_reference', $reference)->exists()) {
                    abort(409, 'Organisation registry reference already exists.');
                }
                $this->db()->table('identity.organisations')->insert([
                    'id' => $id, 'registry_reference' => $reference, 'display_name' => $name,
                    'status' => 'pending_review', 'evidence_reference' => $evidence,
                    'requested_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event('organisation', $id, 'requested', $actorId, null, 'pending_review');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                abort(409, 'Organisation registry reference already exists.');
            }
            throw $exception;
        }
        return ['id' => $id, 'status' => 'pending_review'];
    }

    public function reviewOrganisation(string $id, string $decision, string $actorId, ?string $comments = null): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.review');
        if (! in_array($decision, ['pending_approval', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve for final approval or reject.']);
        }
        $this->db()->transaction(function () use ($id, $decision, $comments, $actorId): void {
            $org = $this->db()->table('identity.organisations')->where('id', $id)->lockForUpdate()->first();
            if ($org === null || $org->status !== 'pending_review') {
                abort(409, 'Organisation is not pending review.');
            }
            if ($org->requested_by === $actorId) {
                abort(403, 'A separate reviewer is required.');
            }
            $this->db()->table('identity.organisations')->where('id', $id)->update([
                'status' => $decision, 'reviewed_by' => $actorId, 'reviewer_comments' => $comments, 'updated_at' => now(),
            ]);
            $this->event('organisation', $id, 'reviewed', $actorId, 'pending_review', $decision);
        });
    }

    /** @return list<array<string, mixed>> */
    public function organisationQueue(string $actorId, string $stage): array
    {
        $permission = match ($stage) {
            'approval' => 'organisation.registry.approve',
            'mine' => 'organisation.registry.request',
            'active' => 'organisation.registry.read',
            default => 'organisation.registry.review',
        };
        $this->requireAdmin($actorId, $permission);
        $query = $this->db()->table('identity.organisations as organisation')
            ->leftJoin('identity.users as creator', 'creator.id', '=', 'organisation.requested_by')
            ->leftJoin('identity.users as reviewer', 'reviewer.id', '=', 'organisation.reviewed_by')
            ->select('organisation.*', 'creator.email as creator_email', 'reviewer.email as reviewer_email');
        if ($stage === 'active') $query->where('organisation.status', 'active');
        elseif ($stage === 'approval') $query->where('organisation.status', 'pending_approval');
        elseif ($stage === 'review') $query->where('organisation.status', 'pending_review');
        else $query->where('organisation.requested_by', $actorId);
        return $query->orderByDesc('organisation.created_at')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    public function organisationDetails(string $id, string $actorId): array
    {
        $this->requireAdmin($actorId, 'organisation.registry.read');
        $row = $this->db()->table('identity.organisations as organisation')
            ->leftJoin('identity.users as creator', 'creator.id', '=', 'organisation.requested_by')
            ->leftJoin('identity.users as reviewer', 'reviewer.id', '=', 'organisation.reviewed_by')
            ->leftJoin('identity.users as approver', 'approver.id', '=', 'organisation.approved_by')
            ->where('organisation.id', $id)
            ->first(['organisation.*', 'creator.email as creator_email', 'reviewer.email as reviewer_email', 'approver.email as approver_email']);
        abort_if($row === null, 404, 'Organisation request not found.');
        $ownsRequest = $row->requested_by === $actorId;
        $canReview = $this->identity->hasPermission($actorId, 'organisation.registry.review');
        $canApprove = $this->identity->hasPermission($actorId, 'organisation.registry.approve');
        $canManage = $this->identity->hasPermission($actorId, 'organisation.registry.revoke');
        $mayView = $ownsRequest
            || ($row->status === 'pending_review' && $canReview)
            || ($row->status === 'pending_approval' && $canApprove)
            || $canManage;
        abort_unless($mayView, 403, 'You cannot view this organisation request.');
        return (array) $row;
    }

    public function approveOrganisation(string $id, string $decision, ?string $comments, string $actorId): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.approve');
        if (! in_array($decision, ['active', 'rejected'], true)) throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        $this->db()->transaction(function () use ($id, $decision, $comments, $actorId): void {
            $org = $this->db()->table('identity.organisations')->where('id', $id)->lockForUpdate()->first();
            if ($org === null || $org->status !== 'pending_approval') abort(409, 'Organisation is not pending approval.');
            if ($org->requested_by === $actorId || $org->reviewed_by === $actorId) abort(403, 'A separate approver is required.');
            $this->db()->table('identity.organisations')->where('id', $id)->update([
                'status' => $decision, 'approved_by' => $actorId, 'approver_comments' => $comments, 'updated_at' => now(),
            ]);
            $this->event('organisation', $id, 'approved', $actorId, 'pending_approval', $decision);
        });
    }

    public function suspendOrganisation(string $id, string $actorId): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.revoke');
        $this->db()->transaction(function () use ($id, $actorId): void {
            $org = $this->db()->table('identity.organisations')->where('id', $id)->lockForUpdate()->first();
            if ($org === null || $org->status !== 'active') {
                abort(409, 'Organisation is not active.');
            }
            $this->db()->table('identity.organisations')->where('id', $id)->update(['status' => 'suspended', 'updated_at' => now()]);
            $this->event('organisation', $id, 'suspended', $actorId, 'active', 'suspended');
        });
    }

    /** @return array<string, mixed> */
    public function requestMembership(string $orgId, string $userId, string $evidence, string $actorId): array
    {
        $this->requireAdmin($actorId, 'organisation.registry.request');
        try {
            return $this->db()->transaction(function () use ($orgId, $userId, $evidence, $actorId): array {
            $org = $this->db()->table('identity.organisations')->where('id', $orgId)->lockForUpdate()->first();
            if ($org === null || $org->status !== 'active') {
                abort(409, 'Organisation is not active.');
            }
            if (! $this->db()->table('identity.users')->where('id', $userId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Target actor must be active.']);
            }
            $membership = $this->db()->table('identity.organisation_memberships')
                ->where('organisation_id', $orgId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($membership !== null && ! in_array($membership->status, ['revoked', 'rejected'], true)) {
                abort(409, 'Membership already exists or is pending.');
            }
            $id = $membership === null ? (string) Uuid::uuid7() : (string) $membership->id;
            $fields = ['status' => 'pending_review', 'evidence_reference' => $evidence,
                'requested_by' => $actorId, 'reviewed_by' => null, 'updated_at' => now()];
            if ($membership === null) {
                $this->db()->table('identity.organisation_memberships')->insert($fields + [
                    'id' => $id, 'organisation_id' => $orgId, 'user_id' => $userId, 'created_at' => now(),
                ]);
            } else {
                $this->db()->table('identity.organisation_memberships')->where('id', $id)->update($fields);
            }
            $this->event('membership', $id, 'requested', $actorId, $membership?->status, 'pending_review');
            return ['id' => $id, 'status' => 'pending_review'];
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                abort(409, 'Membership already exists or is pending.');
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function membershipQueue(string $actorId, string $stage, ?string $organisationId = null): array
    {
        $permission = match ($stage) {
            'review' => 'organisation.registry.review',
            'directory' => 'organisation.registry.read',
            default => 'organisation.registry.request',
        };
        $this->requireAdmin($actorId, $permission);
        if ($stage === 'directory' && $organisationId === null) {
            throw ValidationException::withMessages(['organisation_id' => 'Choose an organisation to view its members.']);
        }
        $query = $this->db()->table('identity.organisation_memberships as membership')
            ->join('identity.organisations as organisation', 'organisation.id', '=', 'membership.organisation_id')
            ->join('identity.users as target', 'target.id', '=', 'membership.user_id')
            ->leftJoin('identity.users as requester', 'requester.id', '=', 'membership.requested_by')
            ->leftJoin('identity.users as reviewer', 'reviewer.id', '=', 'membership.reviewed_by')
            ->select(
                'membership.id', 'membership.organisation_id', 'membership.user_id', 'membership.status',
                'membership.evidence_reference', 'membership.requested_by', 'membership.reviewed_by',
                'membership.created_at', 'membership.updated_at', 'organisation.display_name as organisation_name',
                'organisation.registry_reference', 'target.email as user_email', 'requester.email as requester_email',
                'target.mobile_number as user_mobile_number', 'target.status as user_status',
                'target.actor_type as user_actor_type', 'reviewer.email as reviewer_email',
            );
        if ($stage === 'review') $query->where('membership.status', 'pending_review');
        elseif ($stage === 'directory') $query->where('membership.organisation_id', $organisationId)
            ->where('membership.status', 'active')->where('organisation.status', 'active');
        else $query->where('membership.requested_by', $actorId);
        return $query->orderByDesc('membership.created_at')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function reviewMembership(string $id, string $decision, string $actorId): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.review');
        if (! in_array($decision, ['active', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose active or rejected.']);
        }
        $this->db()->transaction(function () use ($id, $decision, $actorId): void {
            $membership = $this->db()->table('identity.organisation_memberships')->where('id', $id)->lockForUpdate()->first();
            if ($membership === null || $membership->status !== 'pending_review') {
                abort(409, 'Membership is not pending review.');
            }
            if ($membership->requested_by === $actorId || $membership->user_id === $actorId) {
                abort(403, 'A separate reviewer is required.');
            }
            if (! $this->db()->table('identity.organisations')->where('id', $membership->organisation_id)->where('status', 'active')->exists()
                || ! $this->db()->table('identity.users')->where('id', $membership->user_id)->where('status', 'active')->exists()) {
                abort(409, 'Organisation and target actor must remain active.');
            }
            $this->db()->table('identity.organisation_memberships')->where('id', $id)->update([
                'status' => $decision, 'reviewed_by' => $actorId, 'updated_at' => now(),
            ]);
            $this->event('membership', $id, 'reviewed', $actorId, 'pending_review', $decision);
        });
    }

    public function revokeMembership(string $id, string $actorId): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.revoke');
        $this->db()->transaction(function () use ($id, $actorId): void {
            $membership = $this->db()->table('identity.organisation_memberships')->where('id', $id)->lockForUpdate()->first();
            if ($membership === null || $membership->status !== 'active') {
                abort(409, 'Membership is not active.');
            }
            $this->db()->table('identity.organisation_memberships')->where('id', $id)->update(['status' => 'revoked', 'updated_at' => now()]);
            $this->event('membership', $id, 'revoked', $actorId, 'active', 'revoked');
        });
    }

    private function requireAdmin(string $actorId, string $permission): void
    {
        if (! $this->db()->table('identity.users')->where('id', $actorId)->where('status', 'active')
            ->where('actor_type', 'ADMIN')->exists() || ! $this->identity->hasPermission($actorId, $permission)) {
            abort(403, 'Organisation registry permission is required.');
        }
    }

    private function event(string $subjectType, string $subjectId, string $action, string $actorId, ?string $before, string $after): void
    {
        $eventId = (string) Uuid::uuid7();
        $this->db()->table('identity.organisation_authority_events')->insert([
            'id' => $eventId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'action' => $action, 'actor_id' => $actorId, 'previous_status' => $before,
            'new_status' => $after, 'created_at' => now(),
        ]);
        $this->db()->afterCommit(static function () use ($eventId, $subjectType, $subjectId, $action, $actorId, $before, $after): void {
            app(AuditServiceInterface::class)->record(new AuditEventData(
                action: 'identity.organisation_'.$subjectType.'_'.$action,
                actorType: 'admin',
                resourceType: 'identity_organisation_'.$subjectType,
                correlationId: $eventId,
                outcome: 'success',
                actorId: $actorId,
                resourceId: $subjectId,
                beforeState: $before === null ? null : ['status' => $before],
                afterState: ['status' => $after],
            ));
        });
    }
}
