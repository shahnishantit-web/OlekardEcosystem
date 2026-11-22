<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Records a visibly simulated local POC settlement; it does not move funds or post Ledger entries. */
final class EventPocSettlementService
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    public function list(string $eventId, string $actorId): array
    {
        $this->guardPoc();
        $event = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::ATTENDANCE_READ);
        return DB::connection('community_services')->table('community_services.event_poc_settlements')->where('event_id', $eventId)
            ->orderByDesc('requested_at')->get()->map(static fn ($row) => (array) $row)->all();
    }

    public function refunds(string $eventId, string $actorId): array
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true, 404, 'Local POC refunds are unavailable.');
        $event = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::ATTENDANCE_READ);
        return DB::connection('community_services')->table('community_services.event_poc_refunds')->where('event_id', $eventId)
            ->orderByDesc('simulated_at')->get()->map(static fn ($row) => (array) $row)->all();
    }

    public function eventEnd(string $eventId, string $actorId): ?array
    {
        $this->guardPoc();
        $event = DB::connection('community_services')->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::ATTENDANCE_READ);
        $row = DB::connection('community_services')->table('community_services.event_poc_event_closures')->where('event_id', $eventId)->first();
        return $row === null ? null : (array) $row;
    }

    public function simulateEventEnd(string $eventId, string $actorId, string $idempotencyKey): array
    {
        $this->guardPoc();
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'Idempotency-Key is required.');
        $db = DB::connection('community_services');
        return $db->transaction(function () use ($db, $eventId, $actorId, $idempotencyKey): array {
            $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::PAYOUT_REQUEST);
            $existing = $db->table('community_services.event_poc_event_closures')->where('event_id', $eventId)->first();
            if ($existing !== null) {
                abort_unless($existing->actor_id === $actorId && $existing->idempotency_key === $idempotencyKey,
                    409, 'Event end has already been simulated with a different operation.');
                return (array) $existing;
            }
            abort_unless($event->workflow_state === 'published' && $event->lifecycle_state === 'active', 409,
                'Only a published active event can be marked ended in the local POC.');
            abort_unless(! $db->table('community_services.event_inventory_holds')->where('event_id', $eventId)
                ->where('status', 'held')->where('expires_at', '>', now())->exists(), 409,
                'Wait for active ticket holds to expire before simulating event end.');
            abort_unless(! $db->table('community_services.event_orders')->where('event_id', $eventId)
                ->where('order_state', 'awaiting_payment')->exists(), 409,
                'Resolve pending event payments before simulating event end.');
            $now = now();
            $id = (string) Uuid::uuid7();
            $row = ['id' => $id, 'event_id' => $eventId, 'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey, 'simulated_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            $db->table('community_services.event_poc_event_closures')->insert($row);
            $db->table('community_services.event_drafts')->where('id', $eventId)->update(['sales_state' => 'paused', 'updated_at' => $now]);
            $db->table('community_services.event_draft_audit')->insert(['id' => (string) Uuid::uuid7(), 'event_id' => $eventId,
                'actor_id' => $actorId, 'action' => 'poc_event_end_simulated', 'version' => (int) $event->version, 'created_at' => $now]);
            return $row;
        }, 3);
    }

    public function request(string $eventId, string $actorId, string $idempotencyKey): array
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true, 404, 'Local POC settlement is unavailable.');
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'Idempotency-Key is required.');
        $db = DB::connection('community_services');
        return $db->transaction(function () use ($db, $eventId, $actorId, $idempotencyKey): array {
            $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::PAYOUT_REQUEST);
            $pocEndSimulated = $db->table('community_services.event_poc_event_closures')->where('event_id', $eventId)->exists();
            abort_unless(now()->greaterThanOrEqualTo($event->ends_at) || $event->lifecycle_state === 'cancelled' || $pocEndSimulated, 409,
                'A POC settlement can be requested after the event ends or is cancelled.');
            $existing = $db->table('community_services.event_poc_settlements')->where('event_id', $eventId)
                ->where('requested_by_actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) return (array) $existing;
            abort_unless(! $db->table('community_services.event_poc_settlements')->where('event_id', $eventId)
                ->whereIn('status', ['pending_approval', 'simulated_paid'])->exists(), 409,
                'This event already has a pending or completed POC settlement.');
            $amount = (int) $db->table('community_services.event_orders')->where('event_id', $eventId)
                ->where('order_state', 'issued')->where('payment_state', 'confirmed')->where('accounting_state', 'verified')
                ->whereNotIn('id', $db->table('community_services.event_poc_refunds')->select('order_id'))->sum('total_minor');
            abort_unless($amount > 0 && is_string($event->currency_code), 409, 'No verified paid ticket proceeds are available for simulated settlement.');
            $id = (string) Uuid::uuid7();
            $row = ['id' => $id, 'event_id' => $eventId, 'organisation_id' => (string) $event->organisation_id,
                'requested_by_actor_id' => $actorId, 'approved_by_actor_id' => null, 'amount_minor' => $amount,
                'currency_code' => $event->currency_code, 'status' => 'pending_approval',
                'poc_reference' => 'POC-SETTLEMENT-'.$id, 'idempotency_key' => $idempotencyKey,
                'requested_at' => now(), 'approved_at' => null, 'created_at' => now(), 'updated_at' => now()];
            $db->table('community_services.event_poc_settlements')->insert($row);
            $db->table('community_services.event_draft_audit')->insert(['id' => (string) Uuid::uuid7(), 'event_id' => $eventId,
                'actor_id' => $actorId, 'action' => 'poc_settlement_requested', 'version' => (int) $event->version, 'created_at' => now()]);
            return $row;
        }, 3);
    }

    public function approve(string $settlementId, string $actorId, string $idempotencyKey): array
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true, 404, 'Local POC settlement is unavailable.');
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'Idempotency-Key is required.');
        $db = DB::connection('community_services');
        return $db->transaction(function () use ($db, $settlementId, $actorId, $idempotencyKey): array {
            $candidate = $db->table('community_services.event_poc_settlements')->where('id', $settlementId)->first();
            abort_if($candidate === null, 404, 'Settlement request was not found.');
            $event = $db->table('community_services.event_drafts')->where('id', $candidate->event_id)->lockForUpdate()->first();
            $row = $db->table('community_services.event_poc_settlements')->where('id', $settlementId)->lockForUpdate()->first();
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, (string) $event->id, EventPermissions::PAYOUT_APPROVE);
            if ($row->status === 'simulated_paid') {
                abort_unless($row->approved_by_actor_id === $actorId && $row->approve_idempotency_key === $idempotencyKey,
                    409, 'Settlement has already been completed with a different approval.');
                return (array) $row;
            }
            abort_unless($row->status === 'pending_approval', 409, 'Settlement is not awaiting approval.');
            abort_unless($row->requested_by_actor_id !== $actorId, 403, 'Settlement requester and approver must be different people.');
            $now = now();
            $db->table('community_services.event_poc_settlements')->where('id', $settlementId)->update([
                'approved_by_actor_id' => $actorId, 'approve_idempotency_key' => $idempotencyKey,
                'status' => 'simulated_paid', 'approved_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('community_services.event_draft_audit')->insert(['id' => (string) Uuid::uuid7(), 'event_id' => $event->id,
                'actor_id' => $actorId, 'action' => 'poc_settlement_simulated', 'version' => (int) $event->version, 'created_at' => $now]);
            return (array) $db->table('community_services.event_poc_settlements')->where('id', $settlementId)->first();
        }, 3);
    }

    private function guardPoc(): void
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true, 404, 'Local Events POC is unavailable.');
    }
}
