<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Events-owned approval records, protected by the designated organisation-scoped permission assignment. */
final class ComplimentaryAllocationApprovalService
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    /** @return array<string, mixed> */
    public function request(string $eventId, string $categoryId, string $actorId, int $quota, string $validFrom, string $expiresAt, int $expectedVersion, string $idempotencyKey, ?string $correlationId): array
    {
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'A valid Idempotency-Key is required.');
        abort_unless($quota > 0, 422, 'A positive allocation quota is required.');
        $from = now()->parse($validFrom);
        $until = now()->parse($expiresAt);
        abort_unless($from->lt($until) && $until->isFuture(), 422, 'Approval validity must be a future interval.');
        $hash = hash('sha256', json_encode([$eventId, $categoryId, $quota, $from->toIso8601String(), $until->toIso8601String(), $expectedVersion], JSON_THROW_ON_ERROR));

        return $this->db()->transaction(function () use ($eventId, $categoryId, $actorId, $quota, $from, $until, $expectedVersion, $idempotencyKey, $correlationId, $hash): array {
            $event = $this->lockedEvent($eventId);
            abort_unless((int) $event->version === $expectedVersion, 409, 'Event version changed; reload before requesting approval.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::UPDATE);
            abort_unless($event->lifecycle_state === 'active', 409, 'A cancelled event cannot request complimentary approval.');
            $category = $this->db()->table('community_services.event_draft_categories')->where('id', $categoryId)
                ->where('event_id', $eventId)->lockForUpdate()->first();
            abort_if($category === null, 404, 'Allocation was not found for this event.');
            abort_unless($category->allocation_type === 'complimentary' && $quota <= (int) $category->complimentary_quota,
                409, 'Approval requests must match a complimentary allocation and cannot exceed its configured quota.');
            abort_if($until->greaterThan($event->starts_at), 422, 'Approval validity cannot extend beyond event start.');

            $existing = $this->db()->table('community_services.event_complimentary_approvals')
                ->where('organisation_id', $event->organisation_id)->where('event_id', $eventId)
                ->where('requested_by_actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $hash), 409, 'Idempotency key conflicts with the original approval request.');

                return $this->present($existing);
            }
            abort_if($this->db()->table('community_services.event_complimentary_approvals')
                ->where('event_id', $eventId)->where('category_id', $categoryId)->whereIn('status', ['requested', 'approved'])
                ->whereNull('revoked_at')->exists(), 409, 'An active or pending complimentary approval already exists for this allocation.');

            $version = (int) $this->db()->table('community_services.event_complimentary_approvals')
                ->where('event_id', $eventId)->where('category_id', $categoryId)->max('approval_version') + 1;
            $id = (string) Uuid::uuid7();
            $now = now();
            $this->db()->table('community_services.event_complimentary_approvals')->insert([
                'id' => $id, 'organisation_id' => $event->organisation_id, 'event_id' => $eventId,
                'category_id' => $categoryId, 'approval_version' => $version, 'requested_event_version' => $expectedVersion,
                'requested_by_actor_id' => $actorId, 'approved_by_actor_id' => null, 'approval_assignment_id' => null,
                'approved_quota' => $quota, 'valid_from' => $from, 'expires_at' => $until, 'status' => 'requested',
                'provenance_audit_id' => null, 'approved_at' => null, 'revoked_by_actor_id' => null, 'revoked_at' => null,
                'revocation_reason' => null, 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($eventId, $actorId, 'complimentary_approval_requested', $expectedVersion, $correlationId, $now);

            return $this->present($this->requiredRecord($id));
        }, 3);
    }

    /** @return array<string, mixed> */
    public function approve(string $eventId, string $approvalId, string $actorId, int $expectedVersion, ?string $correlationId): array
    {
        return $this->db()->transaction(function () use ($eventId, $approvalId, $actorId, $expectedVersion, $correlationId): array {
            $event = $this->lockedEvent($eventId);
            abort_unless((int) $event->version === $expectedVersion, 409, 'Event version changed; reload before approval.');
            $assignment = $this->authorization->lockOrganisationAssignment(
                $actorId, (string) $event->organisation_id, EventPermissions::COMPLIMENTARY_APPROVE,
            );
            $record = $this->db()->table('community_services.event_complimentary_approvals')->where('id', $approvalId)
                ->where('event_id', $eventId)->where('organisation_id', $event->organisation_id)->lockForUpdate()->first();
            abort_if($record === null, 404, 'Complimentary approval request was not found.');
            abort_unless($record->status === 'requested' && $record->revoked_at === null, 409, 'Complimentary approval request is not awaiting approval.');
            abort_unless((string) $record->requested_by_actor_id !== $actorId, 403, 'Requester and approver must be different people.');
            abort_unless((int) $record->requested_event_version === $expectedVersion, 409, 'The event changed after the approval request; submit a current request.');
            abort_unless($event->lifecycle_state === 'active', 409, 'A cancelled event cannot receive complimentary approval.');
            $category = $this->db()->table('community_services.event_draft_categories')->where('id', $record->category_id)
                ->where('event_id', $eventId)->first();
            abort_if($category === null || $category->allocation_type !== 'complimentary'
                || (int) $record->approved_quota > (int) $category->complimentary_quota, 409,
                'The allocation changed after the complimentary approval request.');

            $now = now();
            $auditId = (string) Uuid::uuid7();
            $this->db()->table('community_services.event_draft_audit')->insert([
                'id' => $auditId, 'event_id' => $eventId, 'actor_id' => $actorId,
                'action' => 'complimentary_approval_approved', 'version' => $expectedVersion,
                'correlation_id' => $correlationId, 'created_at' => $now,
            ]);
            $this->db()->table('community_services.event_complimentary_approvals')->where('id', $approvalId)->update([
                'status' => 'approved', 'approved_by_actor_id' => $actorId, 'approval_assignment_id' => $assignment->id,
                'provenance_audit_id' => $auditId, 'approved_at' => $now, 'updated_at' => $now,
            ]);

            return $this->present($this->requiredRecord($approvalId));
        }, 3);
    }

    /** @return array<string, mixed> */
    public function revoke(string $eventId, string $approvalId, string $actorId, string $reasonCode, ?string $correlationId): array
    {
        abort_unless(trim($reasonCode) !== '' && strlen($reasonCode) <= 80, 422, 'A revocation reason code is required.');

        return $this->db()->transaction(function () use ($eventId, $approvalId, $actorId, $reasonCode, $correlationId): array {
            $event = $this->lockedEvent($eventId);
            $assignment = $this->authorization->lockOrganisationAssignment(
                $actorId, (string) $event->organisation_id, EventPermissions::COMPLIMENTARY_APPROVE,
            );
            $record = $this->db()->table('community_services.event_complimentary_approvals')->where('id', $approvalId)
                ->where('event_id', $eventId)->where('organisation_id', $event->organisation_id)->lockForUpdate()->first();
            abort_if($record === null, 404, 'Complimentary approval was not found.');
            if ($record->revoked_at !== null) {
                return $this->present($record);
            }
            abort_unless($record->status === 'approved', 409, 'Only an approved complimentary allocation can be revoked.');
            $now = now();
            $this->db()->table('community_services.event_complimentary_approvals')->where('id', $approvalId)->update([
                'status' => 'revoked', 'revoked_by_actor_id' => $actorId, 'revoked_at' => $now,
                'revoked_by_assignment_id' => $assignment->id, 'revocation_reason' => $reasonCode, 'updated_at' => $now,
            ]);
            $invitations = $this->db()->table('community_services.event_invitations')
                ->where('event_id', $eventId)->where('category_id', $record->category_id)->where('status', 'active')
                ->whereRaw("approval_snapshot->>'approval_id' = ?", [$approvalId])
                ->lockForUpdate()->get(['id']);
            foreach ($invitations as $invitation) {
                $this->db()->table('community_services.event_invitations')->where('id', $invitation->id)->update([
                    'status' => 'revoked', 'revoked_by_actor_id' => $actorId, 'revoked_at' => $now, 'updated_at' => $now,
                ]);
                $this->db()->table('community_services.event_invitation_audit')->insert([
                    'id' => (string) Uuid::uuid7(), 'invitation_id' => $invitation->id, 'event_id' => $eventId,
                    'organisation_id' => $event->organisation_id, 'actor_id' => $actorId, 'action' => 'revoked',
                    'correlation_id' => $correlationId, 'created_at' => $now,
                ]);
            }
            $this->audit($eventId, $actorId, 'complimentary_approval_revoked', (int) $event->version, $correlationId, $now);

            return $this->present($this->requiredRecord($approvalId));
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $eventId, string $actorId): array
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::READ);

        return $this->db()->table('community_services.event_complimentary_approvals')->where('event_id', $eventId)
            ->orderByDesc('approval_version')->get()->map(fn (object $row): array => $this->present($row))->all();
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'approval_id' => (string) $row->id, 'version' => (int) $row->approval_version,
            'organisation_id' => (string) $row->organisation_id, 'event_id' => (string) $row->event_id,
            'category_id' => (string) $row->category_id, 'requested_event_version' => (int) $row->requested_event_version,
            'requested_by_actor_id' => (string) $row->requested_by_actor_id,
            'approved_by_actor_id' => $row->approved_by_actor_id === null ? null : (string) $row->approved_by_actor_id,
            'approved_quota' => (int) $row->approved_quota, 'valid_from' => (string) $row->valid_from,
            'expires_at' => (string) $row->expires_at, 'status' => (string) $row->status,
            'approval_assignment_id' => $row->approval_assignment_id === null ? null : (string) $row->approval_assignment_id,
            'revoked_by_assignment_id' => $row->revoked_by_assignment_id === null ? null : (string) $row->revoked_by_assignment_id,
            'provenance_audit_id' => $row->provenance_audit_id === null ? null : (string) $row->provenance_audit_id,
            'approved_at' => $row->approved_at, 'revoked_at' => $row->revoked_at,
            'revoked_by_actor_id' => $row->revoked_by_actor_id === null ? null : (string) $row->revoked_by_actor_id,
        ];
    }

    private function lockedEvent(string $eventId): object
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
        abort_if($event === null, 404, 'Event was not found.');

        return $event;
    }

    private function requiredRecord(string $approvalId): object
    {
        $record = $this->db()->table('community_services.event_complimentary_approvals')->where('id', $approvalId)->first();
        if ($record === null) {
            throw new \RuntimeException('Complimentary approval record was not persisted.');
        }

        return $record;
    }

    private function audit(string $eventId, string $actorId, string $action, int $version, ?string $correlationId, mixed $at): void
    {
        $this->db()->table('community_services.event_draft_audit')->insert([
            'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $actorId,
            'action' => $action, 'version' => $version, 'correlation_id' => $correlationId, 'created_at' => $at,
        ]);
    }

    private function db(): Connection
    {
        return DB::connection('community_services');
    }
}
