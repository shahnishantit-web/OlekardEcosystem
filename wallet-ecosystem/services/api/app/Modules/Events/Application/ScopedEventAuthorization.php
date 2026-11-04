<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Support\Facades\DB;

/** Combines Identity's coarse permission with an exact, live Events-owned assignment. */
final class ScopedEventAuthorization
{
    public function __construct(private readonly IdentityModuleContract $identity) {}

    public function assertOrganisation(string $actorId, string $organisationId, string $permission): void
    {
        $this->assertPermission($actorId, $permission);
        $assigned = DB::connection('community_services')->table('community_services.event_authority_assignments')
            ->where('scope_type', 'organisation')->where('scope_id', $organisationId)
            ->where('organisation_id', $organisationId)->where('actor_id', $actorId)
            ->where('permission_slug', $permission)->whereNull('revoked_at')->exists();
        abort_unless($assigned, 403, 'An active organisation-scoped Events assignment is required.');
    }

    public function assertEvent(string $actorId, string $organisationId, string $eventId, string $permission): void
    {
        $this->assertPermission($actorId, $permission);
        $db = DB::connection('community_services');
        $belongsToOrganisation = $db->table('community_services.event_drafts')
            ->where('id', $eventId)->where('organisation_id', $organisationId)->exists();
        abort_unless($belongsToOrganisation, 404, 'Event was not found in the requested organisation.');
        $assigned = $db->table('community_services.event_authority_assignments')
            ->where('scope_type', 'event')->where('scope_id', $eventId)
            ->where('event_id', $eventId)->where('organisation_id', $organisationId)
            ->where('actor_id', $actorId)->where('permission_slug', $permission)
            ->whereNull('revoked_at')->exists();
        abort_unless($assigned, 403, 'An active assignment to this event is required.');
    }

    /** Serializes privileged writes against grant revocation in the same Events transaction. */
    public function lockEventAssignment(string $actorId, string $organisationId, string $eventId, string $permission): void
    {
        $this->assertPermission($actorId, $permission);
        $db = DB::connection('community_services');
        if ($db->transactionLevel() < 1) {
            throw new \LogicException('Event assignment locking must share the protected write transaction.');
        }
        abort_unless($db->table('community_services.event_drafts')
            ->where('id', $eventId)->where('organisation_id', $organisationId)->exists(), 404, 'Event was not found.');
        $assignment = $db->table('community_services.event_authority_assignments')
            ->where('scope_type', 'event')->where('scope_id', $eventId)->where('event_id', $eventId)
            ->where('organisation_id', $organisationId)->where('actor_id', $actorId)
            ->where('permission_slug', $permission)->whereNull('revoked_at')->lockForUpdate()->first();
        abort_unless($assignment !== null, 403, 'An active assignment to this event is required.');
    }

    /** Serializes organisation authority use against grant/revocation in the same transaction. */
    public function lockOrganisationAssignment(string $actorId, string $organisationId, string $permission): object
    {
        $this->assertPermission($actorId, $permission);
        $db = DB::connection('community_services');
        if ($db->transactionLevel() < 1) {
            throw new \LogicException('Organisation assignment locking must share the protected write transaction.');
        }
        $assignment = $db->table('community_services.event_authority_assignments')
            ->where('scope_type', 'organisation')->where('scope_id', $organisationId)
            ->where('organisation_id', $organisationId)->where('actor_id', $actorId)
            ->where('permission_slug', $permission)->whereNull('revoked_at')->lockForUpdate()->first();
        abort_unless($assignment !== null, 403, 'An active organisation-scoped assignment is required.');

        return $assignment;
    }

    public function assertGate(string $actorId, string $organisationId, string $eventId, string $gateId, string $permission): void
    {
        $this->assertPermission($actorId, $permission);
        abort_unless($permission === 'events.gate.validate', 403, 'Gate assignments authorize validation only.');
        $db = DB::connection('community_services');
        $eventExists = $db->table('community_services.event_drafts')
            ->where('id', $eventId)->where('organisation_id', $organisationId)->exists();
        abort_unless($eventExists, 404, 'Event was not found in the requested organisation.');
        if ($db->transactionLevel() < 1) {
            throw new \LogicException('Gate validation authorization must share the admission transaction.');
        }
        $assignment = $db->table('community_services.event_authority_assignments')
            ->where('scope_type', 'gate')->where('scope_id', $gateId)
            ->where('gate_id', $gateId)->where('event_id', $eventId)
            ->where('organisation_id', $organisationId)->where('actor_id', $actorId)
            ->where('permission_slug', 'events.gate.validate')->whereNull('revoked_at')
            ->lockForUpdate()->first();
        abort_unless($assignment !== null, 403, 'An active assignment to this gate is required.');
    }

    /** Gate managers can delegate validation only, and never assign themselves. */
    public function assertCanDelegateGateValidation(
        string $managerId,
        string $targetActorId,
        string $organisationId,
        string $eventId,
        string $permission,
    ): void {
        abort_unless($targetActorId !== $managerId, 403, 'Self-assignment is prohibited.');
        abort_unless($permission === 'events.gate.validate', 403, 'Gate managers may delegate validation only.');
        $this->assertEvent($managerId, $organisationId, $eventId, 'events.gate.assignment.manage');
    }

    private function assertPermission(string $actorId, string $permission): void
    {
        abort_unless($this->identity->hasPermission($actorId, $permission), 403, 'Events permission is required.');
    }
}
