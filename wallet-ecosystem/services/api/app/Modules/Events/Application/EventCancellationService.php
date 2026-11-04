<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Events\EventCancellationEffective;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class EventCancellationService
{
    public function __construct(
        private readonly ScopedEventAuthorization $authorization,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    /** @return array<string, mixed> */
    public function request(string $eventId, string $actorId, int $expectedVersion, string $reason, string $idempotencyKey, ?string $correlationId): array
    {
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'A valid Idempotency-Key is required.');
        abort_unless(trim($reason) !== '', 422, 'A cancellation reason is required.');
        $hash = hash('sha256', json_encode([$eventId, $expectedVersion, trim($reason)], JSON_THROW_ON_ERROR));

        return $this->db()->transaction(function () use ($eventId, $actorId, $expectedVersion, $reason, $idempotencyKey, $correlationId, $hash): array {
            $event = $this->lockEvent($eventId);
            abort_if((int) $event->version !== $expectedVersion, 409, 'Event version changed; reload before continuing.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::CANCEL_REQUEST);

            $existing = $this->db()->table('community_services.event_cancellation_requests')
                ->where('event_id', $eventId)->where('requested_by_actor_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals((string) $existing->request_hash, $hash), 409, 'Idempotency key conflicts with the original cancellation request.');

                return $this->requestResult((string) $existing->id);
            }
            abort_unless($event->lifecycle_state === 'active' && $event->workflow_state === 'published'
                && (int) $event->published_version === (int) $event->version, 409, 'Only the current published active event can be cancelled.');
            abort_if($this->db()->table('community_services.event_cancellation_requests')
                ->where('event_id', $eventId)->where('status', 'pending')->exists(), 409,
                'An event cancellation request is already awaiting review.');

            $now = now();
            $id = (string) Uuid::uuid7();
            $this->db()->table('community_services.event_cancellation_requests')->insert([
                'id' => $id, 'event_id' => $eventId, 'organisation_id' => $event->organisation_id,
                'requested_by_actor_id' => $actorId, 'event_version' => $expectedVersion, 'status' => 'pending',
                'reason' => mb_substr(trim($reason), 0, 1000), 'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($eventId, $actorId, 'cancellation_requested', $expectedVersion, $correlationId, $now);

            return $this->requestResult($id);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function review(string $eventId, string $requestId, string $actorId, int $expectedVersion, bool $approve, ?string $reason, string $idempotencyKey, ?string $correlationId): array
    {
        abort_unless($idempotencyKey !== '' && strlen($idempotencyKey) <= 128, 422, 'A valid Idempotency-Key is required.');
        if (! $approve) {
            abort_unless(is_string($reason) && trim($reason) !== '', 422, 'A review reason is required to reject cancellation.');
        }

        $reviewHash = hash('sha256', json_encode([$eventId, $requestId, $expectedVersion, $approve, trim((string) $reason)], JSON_THROW_ON_ERROR));

        return $this->db()->transaction(function () use ($eventId, $requestId, $actorId, $expectedVersion, $approve, $reason, $idempotencyKey, $correlationId, $reviewHash): array {
            $event = $this->lockEvent($eventId);
            abort_if((int) $event->version !== $expectedVersion, 409, 'Event version changed; reload before continuing.');
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::CANCEL_APPROVE);
            $request = $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)
                ->where('event_id', $eventId)->where('organisation_id', $event->organisation_id)->lockForUpdate()->first();
            abort_if($request === null, 404, 'Cancellation request was not found.');
            if ($request->status !== 'pending') {
                abort_unless($request->reviewed_by_actor_id === $actorId
                    && $request->review_idempotency_key === $idempotencyKey
                    && is_string($request->review_request_hash)
                    && hash_equals($request->review_request_hash, $reviewHash),
                    409, 'Cancellation request has already been reviewed with a different operation.');

                return is_string($request->review_result)
                    ? json_decode($request->review_result, true, 512, JSON_THROW_ON_ERROR)
                    : $this->requestResult($requestId);
            }
            abort_unless((string) $request->requested_by_actor_id !== $actorId, 403, 'Cancellation requester and reviewer must be different people.');
            if ($approve) {
                abort_unless((int) $request->event_version === $expectedVersion, 409, 'The event changed after the cancellation request; reject it and submit a current request.');
            }

            $now = now();
            if (! $approve) {
                $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)->update([
                    'status' => 'rejected', 'reviewed_by_actor_id' => $actorId,
                    'review_reason' => mb_substr(trim((string) $reason), 0, 1000), 'reviewed_at' => $now,
                    'review_idempotency_key' => $idempotencyKey, 'review_request_hash' => $reviewHash, 'updated_at' => $now,
                ]);
                $this->audit($eventId, $actorId, 'cancellation_rejected', $expectedVersion, $correlationId, $now);

                $result = $this->requestResult($requestId);
                $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)
                    ->update(['review_result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return $result;
            }

            abort_unless($event->lifecycle_state === 'active' && $event->workflow_state === 'published'
                && (int) $event->published_version === (int) $event->version, 409, 'Only the current published active event can be cancelled.');

            // Lock order is event -> cancellation request -> tickets, matching registration's event-first protocol.
            $tickets = $this->db()->table('community_services.event_tickets')->where('event_id', $eventId)
                ->where('status', 'valid')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->db()->table('community_services.event_tickets')->where('event_id', $eventId)->where('status', 'valid')->update([
                'status' => 'revoked', 'cancellation_request_id' => $requestId, 'updated_at' => $now,
            ]);
            $this->db()->table('community_services.event_drafts')->where('id', $eventId)->update([
                'lifecycle_state' => 'cancelled', 'sales_state' => 'paused', 'updated_at' => $now,
            ]);
            $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)->update([
                'status' => 'effective', 'reviewed_by_actor_id' => $actorId, 'reviewed_at' => $now,
                'effective_at' => $now, 'review_idempotency_key' => $idempotencyKey,
                'review_request_hash' => $reviewHash, 'updated_at' => $now,
            ]);

            $orders = $this->db()->table('community_services.event_orders')->where('event_id', $eventId)->orderBy('id')->get(['id', 'organisation_id', 'payment_intent_id']);
            foreach ($orders as $order) {
                $this->db()->table('community_services.event_cancellation_financial_cases')->insertOrIgnore([
                    'id' => (string) Uuid::uuid7(), 'cancellation_request_id' => $requestId, 'event_id' => $eventId,
                    'organisation_id' => $order->organisation_id, 'order_id' => $order->id,
                    'payment_intent_id' => $order->payment_intent_id, 'state' => 'pending_reconciliation',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $this->audit($eventId, $actorId, 'cancellation_effective', $expectedVersion, $correlationId, $now);
            $eventPayload = [
                'cancellation_request_id' => $requestId, 'event_id' => $eventId,
                'organisation_id' => (string) $event->organisation_id, 'event_version' => $expectedVersion,
                'reviewed_by_actor_id' => $actorId, 'effective_at' => $now->toIso8601String(),
            ];
            $this->outbox->publishOnConnection(new EventCancellationEffective(
                $eventId, $actorId, $correlationId ?? $requestId, $eventPayload,
            ), 'community_services');

            $result = $this->requestResult($requestId) + [
                'financial_recovery_state' => $orders->isEmpty() ? 'not_required' : 'pending_reconciliation',
                'invalidated_ticket_count' => $tickets->count(),
            ];
            $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)
                ->update(['review_result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function requests(string $eventId, string $actorId): array
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        abort_if($event === null, 404, 'Event was not found.');
        $this->authorization->assertEvent($actorId, (string) $event->organisation_id, $eventId, EventPermissions::READ);

        return $this->db()->table('community_services.event_cancellation_requests')->where('event_id', $eventId)
            ->orderByDesc('requested_at')->get(['id', 'event_version', 'requested_by_actor_id', 'reviewed_by_actor_id', 'status', 'reason', 'review_reason', 'requested_at', 'reviewed_at', 'effective_at'])
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    private function requestResult(string $requestId): array
    {
        $row = (array) $this->db()->table('community_services.event_cancellation_requests')->where('id', $requestId)->first();
        abort_if($row === [], 404, 'Cancellation request was not found.');
        $row['financial_recovery_state'] = $this->db()->table('community_services.event_cancellation_financial_cases')
            ->where('cancellation_request_id', $requestId)->exists() ? 'pending_reconciliation' : 'not_required';

        return $row;
    }

    private function lockEvent(string $eventId): object
    {
        $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
        abort_if($event === null, 404, 'Event was not found.');

        return $event;
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
