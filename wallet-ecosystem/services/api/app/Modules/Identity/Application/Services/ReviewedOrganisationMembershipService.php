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

    public function reviewOrganisation(string $id, string $decision, string $actorId): void
    {
        $this->requireAdmin($actorId, 'organisation.registry.review');
        if (! in_array($decision, ['active', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose active or rejected.']);
        }
        $this->db()->transaction(function () use ($id, $decision, $actorId): void {
            $org = $this->db()->table('identity.organisations')->where('id', $id)->lockForUpdate()->first();
            if ($org === null || $org->status !== 'pending_review') {
                abort(409, 'Organisation is not pending review.');
            }
            if ($org->requested_by === $actorId) {
                abort(403, 'A separate reviewer is required.');
            }
            $this->db()->table('identity.organisations')->where('id', $id)->update([
                'status' => $decision, 'reviewed_by' => $actorId, 'updated_at' => now(),
            ]);
            $this->event('organisation', $id, 'reviewed', $actorId, 'pending_review', $decision);
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
