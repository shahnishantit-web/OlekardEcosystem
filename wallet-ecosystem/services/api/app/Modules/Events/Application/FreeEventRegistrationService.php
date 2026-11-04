<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Events-owned complimentary registration; deliberately has no financial dependencies. */
final class FreeEventRegistrationService
{
    public function __construct(
        private readonly ScopedEventAuthorization $authorization,
        private readonly AdmissionCredentialVault $credentials,
        private readonly InvitationRecipientVerifier $recipientVerifier,
        private readonly ComplimentaryAllocationApprovalContract $complimentaryApproval,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function createInvitation(
        string $eventId,
        string $categoryId,
        string $recipientActorId,
        string $expiresAt,
        int $maxUses,
        string $actorId,
        string $idempotencyKey,
        ?string $correlationId,
    ): array {
        if ($maxUses < 1 || $maxUses > 10000 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['max_uses' => 'Invitation uses must be between 1 and 10000.']);
        }
        $this->recipientVerifier->assertActiveCustomerActor($recipientActorId);
        $now = now();
        $expiry = now()->parse($expiresAt);
        abort_unless($expiry->gt($now), 422, 'Invitation expiry must be in the future.');
        $requestHash = hash('sha256', json_encode([$categoryId, $recipientActorId, $expiry->toIso8601String(), $maxUses], JSON_THROW_ON_ERROR));

        return $this->db()->transaction(function () use (
            $eventId, $categoryId, $recipientActorId, $expiry, $maxUses, $actorId, $idempotencyKey,
            $correlationId, $now, $requestHash,
        ): array {
            $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::UPDATE);
            abort_unless(in_array($event->workflow_state, ['draft', 'approved', 'published'], true), 409,
                'Invitations can only be configured for a manageable event.');
            $category = $this->db()->table('community_services.event_draft_categories')
                ->where('id', $categoryId)->where('event_id', $eventId)->lockForUpdate()->first();
            abort_if($category === null, 404, 'Event allocation was not found.');
            abort_unless(in_array($category->allocation_type, ['free', 'complimentary'], true), 409,
                'Invitations can only be issued for free or complimentary allocations.');
            abort_if($expiry->gt($event->starts_at), 422, 'Invitation expiry cannot be later than event start.');

            $existing = $this->db()->table('community_services.event_invitations')->where('event_id', $eventId)
                ->where('created_by_actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $requestHash), 409,
                    'The invitation idempotency key was used for a different request.');

                return ['invitation_id' => (string) $existing->id,
                    'token' => $this->credentials->recoverForDisplay((string) $existing->token_encrypted),
                    'expires_at' => (string) $existing->expires_at, 'max_uses' => (int) $existing->max_uses];
            }

            $approvalSnapshot = $this->complimentaryApprovalSnapshot($event, $category);
            $secret = $this->credentials->create();
            $id = (string) Uuid::uuid7();
            $this->db()->table('community_services.event_invitations')->insert([
                'id' => $id, 'event_id' => $eventId, 'organisation_id' => $event->organisation_id,
                'category_id' => $categoryId, 'token_hash' => $secret['hash'], 'token_encrypted' => $secret['encrypted'],
                'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'recipient_actor_id' => $recipientActorId, 'valid_from' => $now, 'expires_at' => $expiry,
                'max_uses' => $maxUses, 'uses_count' => 0, 'status' => 'active',
                'approval_snapshot' => $approvalSnapshot === null ? null : json_encode($approvalSnapshot, JSON_THROW_ON_ERROR),
                'created_by_actor_id' => $actorId, 'revoked_by_actor_id' => null, 'revoked_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->writeInvitationAudit($id, $eventId, (string) $event->organisation_id, $actorId, 'issued', $correlationId, $now);

            return ['invitation_id' => $id, 'token' => $secret['plaintext'], 'expires_at' => $expiry->toIso8601String(), 'max_uses' => $maxUses];
        }, 3);
    }

    /** @return array{invitation_id:string,status:string} */
    public function revokeInvitation(string $invitationId, string $actorId, string $correlationId): array
    {
        return $this->db()->transaction(function () use ($invitationId, $actorId, $correlationId): array {
            $invitation = $this->db()->table('community_services.event_invitations')->where('id', $invitationId)->first();
            abort_if($invitation === null, 404, 'Invitation was not found.');
            $event = $this->db()->table('community_services.event_drafts')->where('id', $invitation->event_id)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->assertEvent($actorId, (string) $event->organisation_id, (string) $event->id, EventPermissions::UPDATE);
            $locked = $this->db()->table('community_services.event_invitations')->where('id', $invitationId)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'Invitation was not found.');
            if ($locked->status !== 'revoked') {
                $now = now();
                $this->db()->table('community_services.event_invitations')->where('id', $invitationId)->update([
                    'status' => 'revoked', 'revoked_by_actor_id' => $actorId, 'revoked_at' => $now, 'updated_at' => $now,
                ]);
                $this->writeInvitationAudit($invitationId, (string) $event->id, (string) $event->organisation_id,
                    $actorId, 'revoked', $correlationId, $now);
            }

            return ['invitation_id' => $invitationId, 'status' => 'revoked'];
        }, 3);
    }

    /** @return array{registration_id:string,ticket_id:string,event_id:string,category:string,source:string} */
    public function register(
        string $eventId,
        string $categoryId,
        string $actorId,
        ?string $invitationToken,
        string $idempotencyKey,
        ?string $correlationId,
    ): array {
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key is required.']);
        }
        if ($invitationToken !== null && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $invitationToken) !== 1) {
            abort(422, 'Invitation is invalid or unavailable.');
        }
        $tokenHash = $invitationToken === null ? null : hash('sha256', $invitationToken);
        $requestHash = hash('sha256', json_encode([$categoryId, $tokenHash], JSON_THROW_ON_ERROR));

        return $this->db()->transaction(function () use (
            $eventId, $categoryId, $actorId, $tokenHash, $idempotencyKey, $correlationId, $requestHash,
        ): array {
            $db = $this->db();
            $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $category = $db->table('community_services.event_draft_categories')
                ->where('id', $categoryId)->where('event_id', $eventId)->lockForUpdate()->first();
            abort_if($category === null, 404, 'Event allocation was not found.');
            abort_unless(in_array($category->allocation_type, ['free', 'complimentary'], true)
                && (int) $category->price_minor === 0, 409, 'This allocation is not zero-price.');
            $existing = $db->table('community_services.event_free_registrations')->where('event_id', $eventId)
                ->where('attendee_actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $requestHash), 409,
                    'The idempotency key was used for a different registration.');

                return $this->registrationResult((string) $existing->id);
            }

            abort_unless($event->workflow_state === 'published'
                && (int) $event->published_version === (int) $event->version
                && ($event->lifecycle_state ?? 'active') === 'active', 409,
                'Registration is available only for the current published event.');
            abort_unless($event->sales_state === 'active', 409, 'Free registration is not open.');

            $approvalSnapshot = $this->complimentaryApprovalSnapshot($event, $category);
            if ($category->allocation_type === 'complimentary' && $approvalSnapshot !== null) {
                $approvalUseCount = (int) $db->table('community_services.event_free_registrations')
                    ->where('event_id', $eventId)
                    ->where('category_id', $categoryId)
                    ->where('status', 'registered')
                    ->whereRaw("approval_snapshot->>'approval_id' = ?", [(string) $approvalSnapshot['approval_id']])
                    ->count();
                abort_if($approvalUseCount >= (int) $approvalSnapshot['approved_quota'], 409,
                    'This complimentary approval allocation has reached its approved quota.');
            }

            $invitation = null;
            if ($tokenHash !== null) {
                $invitation = $db->table('community_services.event_invitations')->where('token_hash', $tokenHash)->lockForUpdate()->first();
            }
            if ($category->allocation_type === 'complimentary' && $invitation === null) {
                abort(409, 'Invitation is invalid or unavailable.');
            }
            if ($invitation !== null) {
                $validFrom = $invitation->valid_from === null ? null : now()->parse($invitation->valid_from);
                $expiresAt = now()->parse($invitation->expires_at);
                abort_unless((string) $invitation->event_id === $eventId && (string) $invitation->category_id === $categoryId
                    && (string) $invitation->organisation_id === (string) $event->organisation_id
                    && (string) $invitation->status === 'active' && $invitation->revoked_at === null
                    && $validFrom !== null && $validFrom->lessThanOrEqualTo(now()) && $expiresAt->greaterThan(now())
                    && (int) $invitation->uses_count < (int) $invitation->max_uses
                    && ($invitation->recipient_actor_id === null || (string) $invitation->recipient_actor_id === $actorId),
                    409, 'Invitation is invalid or unavailable.');
            }

            $personCount = (int) $db->table('community_services.event_free_registrations')
                ->where('event_id', $eventId)->where('category_id', $categoryId)->where('attendee_actor_id', $actorId)
                ->where('status', 'registered')->count();
            abort_if($personCount >= (int) $category->max_per_person, 409, 'The attendee limit for this allocation has been reached.');
            $freeCount = (int) $db->table('community_services.event_free_registrations')
                ->where('event_id', $eventId)->where('category_id', $categoryId)->where('status', 'registered')->count();
            $quota = $category->allocation_type === 'complimentary' ? (int) $category->complimentary_quota : (int) $category->capacity;
            abort_if($freeCount >= $quota, 409, 'This allocation has reached its limit.');

            $activePaidHolds = (int) $db->table('community_services.event_inventory_holds')
                ->where('event_id', $eventId)->where('category_id', $categoryId)->where('status', 'held')
                ->where('expires_at', '>', now())->sum('quantity');
            abort_if((int) $category->issued_quantity + $activePaidHolds >= (int) $category->capacity,
                409, 'The event allocation is sold out.');

            $registrationId = (string) Uuid::uuid7();
            $ticketId = (string) Uuid::uuid7();
            $credential = app(AdmissionCredentialVault::class)->create();
            $now = now();
            $source = (string) $category->allocation_type;
            $db->table('community_services.event_free_registrations')->insert([
                'id' => $registrationId, 'event_id' => $eventId, 'organisation_id' => $event->organisation_id,
                'category_id' => $categoryId, 'invitation_id' => $invitation?->id, 'attendee_actor_id' => $actorId,
                'entitlement_source' => $source, 'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'approval_snapshot' => $approvalSnapshot === null ? null : json_encode($approvalSnapshot, JSON_THROW_ON_ERROR),
                'status' => 'registered', 'registered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('community_services.event_tickets')->insert([
                'id' => $ticketId, 'order_id' => null, 'order_line_id' => null, 'free_registration_id' => $registrationId,
                'entitlement_source' => $source, 'entitlement_number' => 1, 'event_id' => $eventId,
                'organisation_id' => $event->organisation_id, 'owner_actor_id' => $actorId, 'category_id' => $categoryId,
                'category_name' => $category->name, 'credential_hash' => $credential['hash'],
                'credential_encrypted' => $credential['encrypted'], 'status' => 'valid',
                'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('community_services.event_draft_categories')->where('id', $categoryId)->increment('issued_quantity');
            if ($invitation !== null) {
                $db->table('community_services.event_invitations')->where('id', $invitation->id)->increment('uses_count');
                $this->writeInvitationAudit((string) $invitation->id, $eventId, (string) $event->organisation_id,
                    $actorId, 'redeemed', $correlationId, $now);
            }
            $db->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $actorId,
                'action' => 'free_entitlement_issued', 'version' => (int) $event->version, 'created_at' => $now,
            ]);

            return ['registration_id' => $registrationId, 'ticket_id' => $ticketId, 'event_id' => $eventId,
                'category' => (string) $category->name, 'source' => $source];
        }, 3);
    }

    /** @return array{registration_id:string,ticket_id:string,event_id:string,category:string,source:string} */
    private function registrationResult(string $registrationId): array
    {
        $row = $this->db()->table('community_services.event_free_registrations as r')
            ->join('community_services.event_tickets as t', 't.free_registration_id', '=', 'r.id')
            ->join('community_services.event_draft_categories as c', 'c.id', '=', 'r.category_id')
            ->where('r.id', $registrationId)->first(['r.id', 't.id as ticket_id', 'r.event_id', 'c.name', 'r.entitlement_source']);
        abort_if($row === null, 409, 'The registration entitlement is unavailable for recovery.');

        return ['registration_id' => (string) $row->id, 'ticket_id' => (string) $row->ticket_id,
            'event_id' => (string) $row->event_id, 'category' => (string) $row->name, 'source' => (string) $row->entitlement_source];
    }

    /** @return array<string, int|string|null>|null */
    private function complimentaryApprovalSnapshot(object $event, object $category): ?array
    {
        if ((string) $category->allocation_type !== 'complimentary') {
            return null;
        }

        $evidence = $this->complimentaryApproval->approvalEvidence(
            (string) $event->organisation_id,
            (string) $event->id,
            (string) $category->id,
        );
        if (! $evidence instanceof ComplimentaryAllocationApprovalEvidence) {
            abort(503, 'Complimentary allocation approval evidence is unavailable.');
        }
        $evidence->assertAppliesTo(
            (string) $event->organisation_id,
            (string) $event->id,
            (string) $category->id,
            (int) $category->complimentary_quota,
            new DateTimeImmutable('now'),
        );

        return $evidence->toSnapshot();
    }

    private function writeInvitationAudit(
        string $invitationId,
        string $eventId,
        string $organisationId,
        string $actorId,
        string $action,
        ?string $correlationId,
        mixed $at,
    ): void {
        $this->db()->table('community_services.event_invitation_audit')->insert([
            'id' => (string) Uuid::uuid7(), 'invitation_id' => $invitationId, 'event_id' => $eventId,
            'organisation_id' => $organisationId, 'actor_id' => $actorId, 'action' => $action,
            'correlation_id' => $correlationId, 'created_at' => $at,
        ]);
    }
}
