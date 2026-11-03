<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Writes scoped authority atomically; no default authority or role grant is created. */
final class EventAssignmentManagementService
{
    private const FINANCE_PERMISSIONS = [
        EventPermissions::PRICE_APPROVE,
        EventPermissions::CURRENCY_ASSIGN,
        EventPermissions::REFUND_EXCEPTION_REVIEW,
    ];

    public function __construct(
        private readonly IdentityModuleContract $identity,
        private readonly OrganisationMembershipAuthority $membership,
        private readonly ScopedEventAuthorization $authorization,
        private readonly BootstrapApprovalEvidenceVerifier $bootstrapEvidence,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function grantOrganisationAssignment(
        string $organisationId,
        string $targetActorId,
        string $permission,
        string $actorId,
        string $reasonCode,
        string $correlationId,
    ): array {
        if ($permission !== EventPermissions::CREATE) {
            throw ValidationException::withMessages(['permission_slug' => 'Only event creation can be assigned at organisation scope.']);
        }

        return $this->grant('organisation', $organisationId, $organisationId, null, null, $targetActorId, $permission, $actorId, $reasonCode, $correlationId);
    }

    /** @return array<string, mixed> */
    public function grantEventAssignment(
        string $eventId,
        string $targetActorId,
        string $permission,
        string $actorId,
        string $reasonCode,
        string $correlationId,
    ): array {
        $event = $this->event($eventId);
        if ($permission === EventPermissions::CREATE || ! in_array($permission, EventPermissions::all(), true)) {
            throw ValidationException::withMessages(['permission_slug' => 'This permission cannot be assigned at event scope.']);
        }
        if ($permission === EventPermissions::GATE_VALIDATE) {
            throw ValidationException::withMessages(['permission_slug' => 'Gate assignment requires a registered gate resource.']);
        }

        return $this->grant('event', $eventId, (string) $event->organisation_id, $eventId, null, $targetActorId, $permission, $actorId, $reasonCode, $correlationId);
    }

    /** @return array<string, mixed> */
    public function createGate(string $eventId, string $name, string $actorId, string $correlationId): array
    {
        $event = $this->event($eventId);
        $organisationId = (string) $event->organisation_id;
        $this->membership->assertActiveMembership($actorId, $organisationId);
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::GATE_ASSIGNMENT_MANAGE);
        $name = trim($name);
        abort_if($name === '', 422, 'Gate name is required.');

        return $this->db()->transaction(function () use ($eventId, $organisationId, $name, $actorId, $correlationId): array {
            $this->membership->assertActiveMembership($actorId, $organisationId);
            $this->authorization->lockEventAssignment($actorId, $organisationId, $eventId, EventPermissions::GATE_ASSIGNMENT_MANAGE);
            $lockedEvent = $this->db()->table('community_services.event_drafts')
                ->where('id', $eventId)->where('organisation_id', $organisationId)->lockForUpdate()->first();
            abort_if($lockedEvent === null, 404, 'Event was not found in the requested organisation.');
            abort_if($this->db()->table('community_services.event_gates')->where('event_id', $eventId)->where('name', $name)->exists(), 409, 'A gate with this name already exists for the event.');
            $now = now();
            /** @var array<string, mixed> $gate */
            $gate = [
                'id' => (string) Uuid::uuid7(), 'organisation_id' => $organisationId,
                'event_id' => $eventId, 'name' => $name, 'status' => 'active',
                'created_by_actor_id' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ];
            $this->db()->table('community_services.event_gates')->insert($gate);
            $this->db()->table('community_services.event_gate_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'gate_id' => $gate['id'], 'event_id' => $eventId,
                'actor_id' => $actorId, 'action' => 'created', 'correlation_id' => $correlationId, 'created_at' => $now,
            ]);
            $this->afterAudit('events.gate.created', 'event_gate', $gate['id'], $actorId, $correlationId, null);

            return $gate;
        });
    }

    /** @return list<array{id: string, event_id: string, name: string, status: string}> */
    public function listGates(string $eventId, string $actorId): array
    {
        $event = $this->event($eventId);
        $organisationId = (string) $event->organisation_id;
        $this->membership->assertActiveMembership($actorId, $organisationId);
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::GATE_ASSIGNMENT_MANAGE);

        return $this->db()->table('community_services.event_gates')->where('event_id', $eventId)
            ->where('organisation_id', $organisationId)->orderBy('name')->get(['id', 'event_id', 'name', 'status'])
            ->map(static fn (object $gate): array => [
                'id' => (string) $gate->id, 'event_id' => (string) $gate->event_id,
                'name' => (string) $gate->name, 'status' => (string) $gate->status,
            ])->all();
    }

    /** @return array<string, mixed> */
    public function grantGateValidation(string $gateId, string $targetActorId, string $actorId, string $reasonCode, string $correlationId): array
    {
        abort_if($actorId === $targetActorId, 403, 'Gate managers cannot assign validation authority to themselves.');
        $gate = $this->gate($gateId);

        return $this->grant('gate', $gateId, (string) $gate->organisation_id, (string) $gate->event_id, $gateId,
            $targetActorId, EventPermissions::GATE_VALIDATE, $actorId, $reasonCode, $correlationId);
    }

    /** @return array<string, mixed> */
    public function revokeAssignment(string $assignmentId, string $actorId, string $reasonCode, string $correlationId): array
    {
        return $this->db()->transaction(function () use ($assignmentId, $actorId, $reasonCode, $correlationId): array {
            $assignment = $this->db()->table('community_services.event_authority_assignments')
                ->where('id', $assignmentId)->lockForUpdate()->first();
            abort_if($assignment === null, 404, 'Events assignment not found.');
            if ($assignment->scope_type === 'gate') {
                $this->membership->assertActiveMembership($actorId, (string) $assignment->organisation_id);
                $this->authorization->lockEventAssignment(
                    $actorId, (string) $assignment->organisation_id, (string) $assignment->event_id,
                    EventPermissions::GATE_ASSIGNMENT_MANAGE,
                );
            } else {
                $authorityType = $this->authorityType((string) $assignment->permission_slug);
                $this->requireAssignmentAdministrator($actorId, (string) $assignment->organisation_id, $authorityType, 'revoke');
            }
            if ($assignment->revoked_at !== null) {
                return (array) $assignment;
            }

            $now = now();
            $this->db()->table('community_services.event_authority_assignments')->where('id', $assignmentId)->update([
                'revoked_by_actor_id' => $actorId, 'revoked_at' => $now, 'updated_at' => $now,
            ]);
            $this->assignmentAudit($assignment, 'revoked', $actorId, $reasonCode, $correlationId);
            $result = (array) $assignment;
            $result['revoked_by_actor_id'] = $actorId;
            $result['revoked_at'] = $now->toISOString();
            $this->afterAudit('events.assignment.revoked', 'event_authority_assignment', $assignmentId, $actorId, $correlationId, $reasonCode);

            return $result;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listAssignmentsIssuedBy(string $organisationId, string $authorityType, string $issuerActorId, string $actorId, string $correlationId): array
    {
        return $this->db()->transaction(function () use ($organisationId, $authorityType, $issuerActorId, $actorId, $correlationId): array {
            $this->validateAuthorityType($authorityType);
            $this->requireAssignmentAdministrator($actorId, $organisationId, $authorityType, 'revoke');
            $query = $this->db()->table('community_services.event_authority_assignments')
                ->where('organisation_id', $organisationId)->where('granted_by_actor_id', $issuerActorId)
                ->orderBy('granted_at')->orderBy('id');
            if ($authorityType === 'finance') {
                $query->whereIn('permission_slug', self::FINANCE_PERMISSIONS);
            } else {
                $query->whereNotIn('permission_slug', self::FINANCE_PERMISSIONS);
            }
            $rows = $query->get()->map(static fn (object $row): array => (array) $row)->all();
            $this->db()->table('community_services.event_assignment_access_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'actor_id' => $actorId,
                'organisation_id' => $organisationId, 'issuer_actor_id' => $issuerActorId,
                'authority_type' => $authorityType, 'correlation_id' => $correlationId, 'created_at' => now(),
            ]);
            $this->afterAudit('events.assignment.issuer_history_viewed', 'organisation', $organisationId, $actorId, $correlationId, $issuerActorId);

            return $rows;
        });
    }

    /** @return array<string, mixed> */
    public function delegateAdministrator(
        string $organisationId,
        string $targetActorId,
        string $authorityType,
        string $approvalReference,
        string $actorId,
        string $correlationId,
    ): array {
        $this->validateAuthorityType($authorityType);
        abort_unless($actorId !== $targetActorId, 403, 'Self-assignment is prohibited.');

        return $this->db()->transaction(function () use ($organisationId, $targetActorId, $authorityType, $approvalReference, $actorId, $correlationId): array {
            $this->requireAssignmentAdministrator($actorId, $organisationId, $authorityType, 'grant');
            $this->membership->assertActiveMembership($targetActorId, $organisationId);
            foreach ([EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE] as $requiredPermission) {
                abort_unless($this->identity->hasPermission($targetActorId, $requiredPermission), 403, 'Target lacks the required assignment-administration permission.');
            }
            $existing = $this->db()->table('community_services.event_assignment_administrators')
                ->where('organisation_id', $organisationId)->where('actor_id', $targetActorId)
                ->where('authority_type', $authorityType)->whereNull('revoked_at')->first();
            if ($existing !== null) {
                abort_unless(
                    $existing->granted_by_actor_id === $actorId && $existing->approval_reference === $approvalReference,
                    409,
                    'An active administrator already exists with different approval evidence.',
                );

                return (array) $existing;
            }
            $record = $this->administratorRecord($organisationId, $targetActorId, $authorityType, 'delegated', $actorId, null, null, $approvalReference);
            $this->db()->table('community_services.event_assignment_administrators')->insert($record);
            $this->administratorAudit($record, 'delegated', $actorId, $correlationId, null);
            $this->afterAudit('events.assignment_administrator.delegated', 'event_assignment_administrator', $record['id'], $actorId, $correlationId, $approvalReference);

            return $record;
        });
    }

    /** @return array<string, mixed> */
    public function revokeAdministrator(string $administratorId, string $actorId, string $reasonCode, string $correlationId): array
    {
        return $this->db()->transaction(function () use ($administratorId, $actorId, $reasonCode, $correlationId): array {
            $record = $this->db()->table('community_services.event_assignment_administrators')
                ->where('id', $administratorId)->lockForUpdate()->first();
            abort_if($record === null, 404, 'Assignment administrator not found.');
            $this->requireAssignmentAdministrator($actorId, (string) $record->organisation_id, (string) $record->authority_type, 'revoke');
            if ($record->revoked_at !== null) {
                return (array) $record;
            }
            $now = now();
            $this->db()->table('community_services.event_assignment_administrators')->where('id', $administratorId)->update([
                'revoked_by_actor_id' => $actorId, 'revoked_at' => $now, 'updated_at' => $now,
            ]);
            $updated = (array) $record;
            $updated['revoked_by_actor_id'] = $actorId;
            $updated['revoked_at'] = $now->toISOString();
            $this->administratorAudit($updated, 'revoked', $actorId, $correlationId, $reasonCode);
            $this->afterAudit('events.assignment_administrator.revoked', 'event_assignment_administrator', $administratorId, $actorId, $correlationId, $reasonCode);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function bootstrapAdministrator(
        string $organisationId,
        string $targetActorId,
        string $authorityType,
        string $approverOneId,
        string $approverTwoId,
        string $approvalReference,
        string $operatorActorId,
        string $correlationId,
    ): array {
        $this->validateAuthorityType($authorityType);
        if ($approverOneId === $approverTwoId || in_array($targetActorId, [$approverOneId, $approverTwoId], true)) {
            throw ValidationException::withMessages(['approvers' => 'Two distinct approvers must differ from the target administrator.']);
        }
        abort_if($operatorActorId === $targetActorId, 403, 'Bootstrap operator cannot assign authority to themself.');

        return $this->db()->transaction(function () use ($organisationId, $targetActorId, $authorityType, $approverOneId, $approverTwoId, $approvalReference, $operatorActorId, $correlationId): array {
            $this->db()->selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['p25-assignment-bootstrap:'.$organisationId.':'.$authorityType]);
            $activeAdministrator = $this->db()->table('community_services.event_assignment_administrators')
                ->where('organisation_id', $organisationId)->where('authority_type', $authorityType)->whereNull('revoked_at')->first();
            if ($activeAdministrator !== null) {
                abort_unless(
                    $activeAdministrator->actor_id === $targetActorId
                    && $activeAdministrator->granted_by_actor_id === $operatorActorId
                    && $activeAdministrator->approver_one_actor_id === $approverOneId
                    && $activeAdministrator->approver_two_actor_id === $approverTwoId
                    && $activeAdministrator->approval_reference === $approvalReference,
                    409,
                    'An active administrator already exists; use the delegated authority path.',
                );

                return (array) $activeAdministrator;
            }
            $this->membership->assertActiveMembership($targetActorId, $organisationId);
            $this->membership->assertActiveMembership($operatorActorId, $organisationId);
            abort_unless($this->identity->hasPermission($operatorActorId, EventPermissions::ASSIGNMENT_BOOTSTRAP_EXECUTE), 403, 'Bootstrap execution requires its explicit permission.');
            foreach ([$approverOneId, $approverTwoId] as $approverId) {
                $this->membership->assertActiveMembership($approverId, $organisationId);
                abort_unless($this->identity->hasPermission($approverId, EventPermissions::ASSIGNMENT_BOOTSTRAP_APPROVE), 403, 'Each bootstrap approver must hold the explicit bootstrap approval permission.');
            }
            foreach ([EventPermissions::ASSIGNMENT_GRANT, EventPermissions::ASSIGNMENT_REVOKE] as $requiredPermission) {
                abort_unless($this->identity->hasPermission($targetActorId, $requiredPermission), 403, 'Target lacks the required assignment-administration permission.');
            }
            $this->assertApprovalReferenceAvailable($approvalReference);
            $this->bootstrapEvidence->assertApproved(
                $organisationId, $targetActorId, $authorityType, [$approverOneId, $approverTwoId], $approvalReference,
            );
            $record = $this->administratorRecord($organisationId, $targetActorId, $authorityType, 'bootstrap', $operatorActorId, $approverOneId, $approverTwoId, $approvalReference);
            $this->db()->table('community_services.event_assignment_administrators')->insert($record);
            $this->administratorAudit($record, 'bootstrapped', $operatorActorId, $correlationId, null);
            $this->afterAudit('events.assignment_administrator.bootstrapped', 'event_assignment_administrator', $record['id'], $operatorActorId, $correlationId, $approvalReference);

            return $record;
        });
    }

    /** @return array<string, mixed> */
    private function grant(string $scopeType, string $scopeId, string $organisationId, ?string $eventId, ?string $gateId, string $targetActorId, string $permission, string $actorId, string $reasonCode, string $correlationId): array
    {
        return $this->db()->transaction(function () use ($scopeType, $scopeId, $organisationId, $eventId, $gateId, $targetActorId, $permission, $actorId, $reasonCode, $correlationId): array {
            if ($scopeType === 'gate') {
                abort_unless($permission === EventPermissions::GATE_VALIDATE && $eventId !== null && $gateId !== null, 403, 'Gate managers may delegate gate validation only.');
                $gate = $this->db()->table('community_services.event_gates')->where('id', $gateId)
                    ->where('event_id', $eventId)->where('organisation_id', $organisationId)
                    ->where('status', 'active')->lockForUpdate()->first();
                abort_if($gate === null, 404, 'Active gate does not belong to the requested event.');
                $this->membership->assertActiveMembership($actorId, $organisationId);
                $this->authorization->lockEventAssignment($actorId, $organisationId, $eventId, EventPermissions::GATE_ASSIGNMENT_MANAGE);
            } else {
                $authorityType = $this->authorityType($permission);
                $this->requireAssignmentAdministrator($actorId, $organisationId, $authorityType, 'grant');
            }
            $this->membership->assertActiveMembership($targetActorId, $organisationId);
            if ($scopeType === 'event') {
                $this->event((string) $eventId, $organisationId);
            }
            $existing = $this->db()->table('community_services.event_authority_assignments')
                ->where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('actor_id', $targetActorId)
                ->where('permission_slug', $permission)->whereNull('revoked_at')->first();
            if ($existing !== null) {
                return (array) $existing;
            }
            $now = now();
            $assignment = [
                'id' => (string) Uuid::uuid7(), 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'organisation_id' => $organisationId, 'event_id' => $eventId, 'gate_id' => $gateId,
                'actor_id' => $targetActorId, 'permission_slug' => $permission,
                'granted_by_actor_id' => $actorId, 'granted_at' => $now,
                'revoked_by_actor_id' => null, 'revoked_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $inserted = $this->db()->table('community_services.event_authority_assignments')->insertOrIgnore($assignment);
            if ($inserted === 0) {
                $duplicate = $this->db()->table('community_services.event_authority_assignments')
                    ->where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('actor_id', $targetActorId)
                    ->where('permission_slug', $permission)->whereNull('revoked_at')->first();
                abort_if($duplicate === null, 409, 'Assignment conflicts with an existing authority record.');

                return (array) $duplicate;
            }
            $this->assignmentAudit((object) $assignment, 'granted', $actorId, $reasonCode, $correlationId);
            $this->afterAudit('events.assignment.granted', 'event_authority_assignment', $assignment['id'], $actorId, $correlationId, $reasonCode);

            return $assignment;
        });
    }

    private function requireAssignmentAdministrator(string $actorId, string $organisationId, string $authorityType, string $action): object
    {
        $permission = $action === 'grant' ? EventPermissions::ASSIGNMENT_GRANT : EventPermissions::ASSIGNMENT_REVOKE;
        abort_unless($this->identity->hasPermission($actorId, $permission), 403, 'Assignment administration permission is required.');
        $this->membership->assertActiveMembership($actorId, $organisationId);
        $record = $this->db()->table('community_services.event_assignment_administrators')
            ->where('organisation_id', $organisationId)->where('actor_id', $actorId)
            ->where('authority_type', $authorityType)->whereNull('revoked_at')->lockForUpdate()->first();
        abort_unless($record !== null, 403, 'An active organisation-scoped assignment-administrator designation is required.');

        return $record;
    }

    private function authorityType(string $permission): string
    {
        abort_unless(in_array($permission, EventPermissions::all(), true), 422, 'Unknown or non-assignable Events permission.');

        return in_array($permission, self::FINANCE_PERMISSIONS, true) ? 'finance' : 'organisation';
    }

    private function validateAuthorityType(string $authorityType): void
    {
        abort_unless(in_array($authorityType, ['organisation', 'finance'], true), 422, 'Authority type must be organisation or finance.');
    }

    private function event(string $eventId, ?string $organisationId = null): object
    {
        $query = $this->db()->table('community_services.event_drafts')->where('id', $eventId);
        if ($organisationId !== null) {
            $query->where('organisation_id', $organisationId);
        }
        $event = $query->first();
        abort_if($event === null, 404, 'Event was not found in the requested organisation.');

        return $event;
    }

    private function gate(string $gateId): object
    {
        $gate = $this->db()->table('community_services.event_gates')
            ->where('id', $gateId)->where('status', 'active')->first();
        abort_if($gate === null, 404, 'Active event gate was not found.');

        return $gate;
    }

    /** @return array<string, mixed> */
    private function administratorRecord(string $organisationId, string $actorId, string $authorityType, string $source, string $grantorId, ?string $approverOne, ?string $approverTwo, string $approvalReference): array
    {
        abort_if(trim($approvalReference) === '', 422, 'An approval reference is required.');
        $this->assertApprovalReferenceAvailable($approvalReference);

        return [
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $organisationId,
            'actor_id' => $actorId, 'authority_type' => $authorityType, 'grant_source' => $source,
            'granted_by_actor_id' => $grantorId, 'approver_one_actor_id' => $approverOne,
            'approver_two_actor_id' => $approverTwo, 'approval_reference' => $approvalReference,
            'granted_at' => now(), 'revoked_by_actor_id' => null, 'revoked_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertApprovalReferenceAvailable(string $approvalReference): void
    {
        abort_if(trim($approvalReference) === '', 422, 'An approval reference is required.');
        abort_if(
            $this->db()->table('community_services.event_assignment_administrators')->where('approval_reference', $approvalReference)->exists(),
            409,
            'Approval reference has already been used.',
        );
    }

    private function assignmentAudit(object $assignment, string $action, string $actorId, ?string $reason, string $correlationId): void
    {
        $this->db()->table('community_services.event_authority_assignment_audit')->insert([
            'id' => (string) Uuid::uuid7(), 'assignment_id' => $assignment->id, 'action' => $action,
            'actor_id' => $actorId, 'target_actor_id' => $assignment->actor_id,
            'scope_type' => $assignment->scope_type, 'scope_id' => $assignment->scope_id,
            'permission_slug' => $assignment->permission_slug, 'reason_code' => $reason,
            'correlation_id' => $correlationId, 'created_at' => now(),
        ]);
    }

    private function administratorAudit(array $record, string $action, string $actorId, string $correlationId, ?string $reason): void
    {
        $this->db()->table('community_services.event_assignment_administrator_audit')->insert([
            'id' => (string) Uuid::uuid7(), 'administrator_record_id' => $record['id'], 'action' => $action,
            'actor_id' => $actorId, 'target_actor_id' => $record['actor_id'],
            'organisation_id' => $record['organisation_id'], 'authority_type' => $record['authority_type'],
            'approver_one_actor_id' => $record['approver_one_actor_id'],
            'approver_two_actor_id' => $record['approver_two_actor_id'],
            'approval_reference' => $record['approval_reference'], 'reason_code' => $reason,
            'correlation_id' => $correlationId, 'created_at' => now(),
        ]);
    }

    private function afterAudit(string $action, string $resourceType, string $resourceId, string $actorId, string $correlationId, ?string $reason): void
    {
        $this->db()->afterCommit(static function () use ($action, $resourceType, $resourceId, $actorId, $correlationId, $reason): void {
            app(AuditServiceInterface::class)->record(new AuditEventData(
                action: $action, actorType: 'user', resourceType: $resourceType,
                correlationId: $correlationId, outcome: 'success', actorId: $actorId,
                resourceId: $resourceId, afterState: ['reason_code' => $reason],
            ));
        });
    }
}
