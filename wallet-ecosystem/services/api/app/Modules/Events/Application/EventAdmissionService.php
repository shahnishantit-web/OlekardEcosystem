<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Payment\Domain\Contracts\PaymentRefundAdmissionEligibilityContract;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Online-only admission and privacy-safe attendance queries. */
final class EventAdmissionService
{
    public function __construct(
        private readonly ScopedEventAuthorization $authorization,
        private readonly AdmissionCredentialVault $credentials,
        private readonly ActorStepUpVerifierContract $stepUpVerifier,
        private readonly PaymentRefundAdmissionEligibilityContract $refundEligibility,
    ) {}

    /**
     * The credential is never returned, persisted, audited or logged.
     *
     * @return array{status: string, category: string|null}
     */
    public function validateOnline(string $eventId, string $gateId, string $actorId, string $credential, ?string $correlationId): array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{43}\z/', $credential) === 1, 422, 'Credential is invalid.');
        $db = $this->db();

        /** @var \Closure(): array{status: string, category: string|null} $admit */
        $admit = function () use ($db, $eventId, $gateId, $actorId, $credential, $correlationId): array {
            $gate = $db->table('community_services.event_gates')->where('id', $gateId)
                ->where('event_id', $eventId)->where('status', 'active')->lockForUpdate()->first();
            abort_if($gate === null, 404, 'Active gate was not found for this event.');

            // Assignment row locking is the serialization point against revocation.
            $this->authorization->assertGate($actorId, (string) $gate->organisation_id, $eventId, $gateId, EventPermissions::GATE_VALIDATE);

            $ticket = $db->table('community_services.event_tickets')->where('credential_hash', hash('sha256', $credential))
                ->where('event_id', $eventId)->where('organisation_id', $gate->organisation_id)->lockForUpdate()->first();
            if ($ticket === null) {
                return ['status' => 'denied', 'category' => null];
            }
            if ($ticket->status !== 'valid' || $this->eventNotAdmissionEligible($db, $eventId)
                || ! $this->entitlementEligibleForAdmission($db, $ticket)
                || ($ticket->entitlement_source === 'paid'
                    && $this->refundBlocksAdmission((string) $this->paymentIntentForTicket($db, (string) $ticket->id)))) {
                return ['status' => 'denied', 'category' => (string) $ticket->category_name];
            }

            $existing = $db->table('community_services.event_attendance')->where('ticket_id', $ticket->id)->first();
            if ($existing !== null) {
                return ['status' => 'already_admitted', 'category' => (string) $ticket->category_name];
            }

            $attendanceId = (string) Uuid::uuid7();
            $now = now();
            $db->table('community_services.event_attendance')->insert([
                'id' => $attendanceId, 'ticket_id' => $ticket->id, 'event_id' => $eventId,
                'organisation_id' => $gate->organisation_id, 'gate_id' => $gateId, 'operator_actor_id' => $actorId,
                'category_id' => $ticket->category_id, 'category_name' => $ticket->category_name,
                'admission_state' => 'admitted', 'admitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('community_services.event_attendance_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'attendance_id' => $attendanceId, 'ticket_id' => $ticket->id,
                'event_id' => $eventId, 'organisation_id' => $gate->organisation_id, 'gate_id' => $gateId,
                'operator_actor_id' => $actorId, 'category_id' => $ticket->category_id,
                'action' => 'admitted', 'correlation_id' => $correlationId, 'created_at' => $now,
            ]);

            return ['status' => 'admitted', 'category' => (string) $ticket->category_name];
        };

        return $db->transaction($admit, 3);
    }

    /** @return list<array{ticket_id:string,event_id:string,event_title:string,category:string,status:string,credential:string}> */
    public function ticketsForPurchaser(string $actorId): array
    {
        return $this->db()->table('community_services.event_tickets as t')
            ->join('community_services.event_drafts as e', 'e.id', '=', 't.event_id')
            ->where('t.owner_actor_id', $actorId)->where('t.status', 'valid')
            ->orderBy('e.starts_at')->orderBy('t.created_at')->get([
                't.id', 't.event_id', 'e.title as event_title', 't.category_name', 't.status', 't.credential_encrypted',
            ])->map(fn (object $ticket): array => [
                'ticket_id' => (string) $ticket->id,
                'event_id' => (string) $ticket->event_id,
                'event_title' => (string) $ticket->event_title,
                'category' => (string) $ticket->category_name,
                'status' => $this->ticketStatus((string) $ticket->id),
                'credential' => $this->credentials->recoverForDisplay((string) $ticket->credential_encrypted),
            ])->all();
    }

    /** @return array{ticket_id:string,event_id:string,event_title:string,category:string,status:string,credential:string} */
    public function ticketForPurchaser(string $ticketId, string $actorId): array
    {
        $ticket = $this->db()->table('community_services.event_tickets as t')
            ->join('community_services.event_drafts as e', 'e.id', '=', 't.event_id')
            ->where('t.id', $ticketId)->where('t.owner_actor_id', $actorId)
            ->first(['t.id', 't.event_id', 'e.title as event_title', 't.category_name', 't.status', 't.credential_encrypted']);
        abort_if($ticket === null, 404, 'Ticket was not found.');
        abort_unless($ticket->status === 'valid', 404, 'Ticket was not found.');

        return [
            'ticket_id' => (string) $ticket->id, 'event_id' => (string) $ticket->event_id,
            'event_title' => (string) $ticket->event_title, 'category' => (string) $ticket->category_name,
            'status' => $this->ticketStatus((string) $ticket->id),
            'credential' => $this->credentials->recoverForDisplay((string) $ticket->credential_encrypted),
        ];
    }

    /** Aggregate-only report: deliberately has no attendee/ticket identifiers. */
    public function aggregateAttendance(string $eventId, string $actorId): array
    {
        $db = $this->db();
        $event = $db->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::ATTENDANCE_READ);

        $noShowsFinalized = now()->greaterThanOrEqualTo($event->ends_at);

        return $db->table('community_services.event_draft_categories as c')->where('c.event_id', $eventId)
            ->select('c.id', 'c.name')
            ->selectSub(function ($query) use ($eventId): void {
                $query->from('community_services.event_free_registrations as r')->selectRaw('COUNT(*)')
                    ->whereColumn('r.category_id', 'c.id')->where('r.event_id', $eventId)->where('r.status', 'registered');
            }, 'free_registrations')
            ->selectSub(function ($query) use ($eventId): void {
                $query->from('community_services.event_tickets as t')->selectRaw('COUNT(*)')
                    ->whereColumn('t.category_id', 'c.id')->where('t.event_id', $eventId)
                    ->where('t.entitlement_source', 'paid')->where('t.status', 'valid');
            }, 'paid_sales')
            ->selectSub(function ($query) use ($eventId): void {
                $query->from('community_services.event_attendance as a')->selectRaw('COUNT(*)')
                    ->whereColumn('a.category_id', 'c.id')->where('a.event_id', $eventId);
            }, 'unique_admissions')
            ->selectSub(function ($query) use ($eventId): void {
                $query->from('community_services.event_attendance as a')->selectRaw('COUNT(*)')
                    ->whereColumn('a.category_id', 'c.id')->where('a.event_id', $eventId);
            }, 'admitted_count')
            ->orderBy('c.name')->get()
            ->map(function (object $row) use ($eventId, $noShowsFinalized): array {
                $noShows = $noShowsFinalized ? $this->countEligibleNoShows($eventId, (string) $row->id) : null;

                return [
                    'category' => (string) $row->name,
                    'free_registrations' => (int) $row->free_registrations,
                    'paid_sales' => (int) $row->paid_sales,
                    'unique_admissions' => (int) $row->unique_admissions,
                    'admitted_count' => (int) $row->admitted_count,
                    'no_shows' => $noShows,
                    'no_shows_finalized' => $noShowsFinalized,
                ];
            })->all();
    }

    /** Identity step-up is mandatory; old and new credential state changes atomically. */
    public function replaceCredential(string $ticketId, string $actorId, string $sessionId, string $stepUpToken, string $idempotencyKey): array
    {
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'A valid Idempotency-Key is required.');
        $scope = 'event_ticket.credential.replace:'.$ticketId;
        $stepUpFingerprint = hash('sha256', $stepUpToken);

        return $this->db()->transaction(function () use ($ticketId, $actorId, $sessionId, $stepUpToken, $stepUpFingerprint, $scope, $idempotencyKey): array {
            $ticket = $this->db()->table('community_services.event_tickets')->where('id', $ticketId)->lockForUpdate()->first();
            abort_if($ticket === null || $ticket->owner_actor_id !== $actorId, 404, 'Ticket was not found.');
            abort_unless($ticket->status === 'valid', 409, 'Ticket credential is not eligible for replacement.');
            abort_if($this->db()->table('community_services.event_attendance')->where('ticket_id', $ticketId)->exists(), 409,
                'An admitted ticket credential cannot be replaced.');
            $audit = $this->db()->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($audit !== null) {
                abort_unless(is_string($audit->step_up_token_fingerprint)
                    && hash_equals($audit->step_up_token_fingerprint, $stepUpFingerprint), 409,
                    'Credential replacement idempotency key conflicts with its original step-up proof.');

                return ['ticket_id' => $ticketId, 'status' => 'unchanged'];
            }
            abort_if($this->db()->table('community_services.event_ticket_security_audit')->where('ticket_id', $ticketId)
                ->where('step_up_token_fingerprint', $stepUpFingerprint)->exists(), 409,
                'Identity step-up proof has already been used for this ticket.');
            abort_unless($this->stepUpVerifier->verifyStepUpForActor($stepUpToken, $scope, $actorId, $sessionId), 403,
                'Identity step-up verification is required.');
            $replacement = $this->credentials->create();
            $now = now();
            $this->db()->table('community_services.event_tickets')->where('id', $ticketId)->update([
                'credential_hash' => $replacement['hash'], 'credential_encrypted' => $replacement['encrypted'], 'updated_at' => $now,
            ]);
            $this->db()->table('community_services.event_ticket_security_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'ticket_id' => $ticketId, 'actor_id' => $actorId,
                'action' => 'credential_rotated', 'idempotency_key' => $idempotencyKey,
                'step_up_token_fingerprint' => $stepUpFingerprint, 'created_at' => $now,
            ]);

            return ['ticket_id' => $ticketId, 'status' => 'rotated'];
        }, 3);
    }

    private function refundBlocksAdmission(string $paymentIntentId): bool
    {
        return $this->refundEligibility->hasAdmissionBlockingRefund($paymentIntentId);
    }

    private function entitlementEligibleForAdmission(Connection $db, object $ticket): bool
    {
        if ($ticket->entitlement_source === 'paid') {
            return $ticket->order_id !== null && $ticket->order_line_id !== null && $ticket->free_registration_id === null;
        }
        if (! in_array($ticket->entitlement_source, ['free', 'complimentary'], true)
            || $ticket->order_id !== null || $ticket->order_line_id !== null || ! is_string($ticket->free_registration_id)) {
            return false;
        }

        return $db->table('community_services.event_free_registrations')
            ->where('id', $ticket->free_registration_id)->where('event_id', $ticket->event_id)
            ->where('organisation_id', $ticket->organisation_id)->where('category_id', $ticket->category_id)
            ->where('attendee_actor_id', $ticket->owner_actor_id)->where('entitlement_source', $ticket->entitlement_source)
            ->where('status', 'registered')->exists();
    }

    private function countEligibleNoShows(string $eventId, string $categoryId): int
    {
        $db = $this->db();
        $candidates = $db->table('community_services.event_tickets as t')
            ->where('t.event_id', $eventId)->where('t.category_id', $categoryId)->where('t.status', 'valid')
            ->whereNotExists(function ($attendance): void {
                $attendance->selectRaw('1')->from('community_services.event_attendance as a')
                    ->whereColumn('a.ticket_id', 't.id');
            })->get();
        $count = 0;
        foreach ($candidates as $ticket) {
            if (! $this->entitlementEligibleForAdmission($db, $ticket)) {
                continue;
            }
            if ($ticket->entitlement_source === 'paid'
                && $this->refundBlocksAdmission($this->paymentIntentForTicket($db, (string) $ticket->id))) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function paymentIntentForTicket(Connection $db, string $ticketId): string
    {
        $intentId = $db->table('community_services.event_tickets as t')
            ->join('community_services.event_orders as o', 'o.id', '=', 't.order_id')
            ->where('t.id', $ticketId)->value('o.payment_intent_id');
        abort_if(! is_string($intentId) || $intentId === '', 409, 'Ticket payment evidence is unavailable.');

        return $intentId;
    }

    private function eventNotAdmissionEligible(Connection $db, string $eventId): bool
    {
        $event = $db->table('community_services.event_drafts')->where('id', $eventId)->first(['workflow_state', 'version', 'published_version']);

        return $event === null || $event->workflow_state !== 'published'
            || (int) $event->published_version !== (int) $event->version;
    }

    private function ticketStatus(string $ticketId): string
    {
        $attended = $this->db()->table('community_services.event_attendance')->where('ticket_id', $ticketId)->exists();

        return $attended ? 'admitted' : 'valid';
    }

    private function db(): Connection
    {
        return DB::connection('community_services');
    }
}
