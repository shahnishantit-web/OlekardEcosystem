<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Exceptions\CurrencyReviewRequired;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Fail-closed inventory holds; this service does not start payments or issue tickets. */
final class EventInventoryHoldService
{
    public function __construct(private readonly EventCurrencyAssignmentService $currency) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function createHold(
        string $eventId,
        string $categoryId,
        string $customerActorId,
        int $quantity,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        if ($quantity < 1 || trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['quantity' => 'A positive quantity and bounded idempotency key are required.']);
        }
        $requestHash = hash('sha256', json_encode([$categoryId, $quantity], JSON_THROW_ON_ERROR));

        try {
            return $this->db()->transaction(function () use (
                $eventId, $categoryId, $customerActorId, $quantity, $idempotencyKey, $correlationId, $requestHash,
            ): array {
                // Event-row locking serializes every reservation and races with event edits.
                $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
                abort_if($event === null, 404, 'Event was not found.');
                $now = now()->toImmutable();
                $this->expireCategoryHoldsDue($eventId, $categoryId, $now);
                $existing = $this->db()->table('community_services.event_inventory_holds')
                    ->where('event_id', $eventId)->where('customer_actor_id', $customerActorId)
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    abort_unless(hash_equals((string) $existing->request_hash, $requestHash), 409,
                        'The idempotency key was already used for a different hold request.');

                    return (array) $existing;
                }

                if ($event->workflow_state !== 'published' || $event->sales_state !== 'active'
                    || ($event->lifecycle_state ?? 'active') !== 'active'
                    || (int) $event->published_version !== (int) $event->version) {
                    abort(409, 'Inventory can be held only for the active, current published event version.');
                }
                if ($event->hold_duration_seconds === null || (int) $event->hold_duration_seconds < 1) {
                    abort(409, 'No positive inventory hold duration is configured.');
                }
                $salesStart = new DateTimeImmutable((string) $event->sales_start_at);
                $salesEnd = new DateTimeImmutable((string) $event->sales_end_at);
                $eventStart = new DateTimeImmutable((string) $event->starts_at);
                if ($now < $salesStart || $now >= $salesEnd || $now >= $eventStart) {
                    abort(409, 'The event is outside its approved sales window.');
                }

                $category = $this->db()->table('community_services.event_draft_categories')
                    ->where('id', $categoryId)->where('event_id', $eventId)->lockForUpdate()->first();
                abort_if($category === null, 404, 'Ticket category was not found for this event.');
                abort_unless(($category->allocation_type ?? 'paid') === 'paid' && (int) $category->price_minor > 0,
                    409, 'Free and complimentary allocations cannot enter paid checkout.');
                $activeHeld = (int) $this->db()->table('community_services.event_inventory_holds')
                    ->where('event_id', $eventId)->where('category_id', $categoryId)
                    ->where('status', 'held')->where('expires_at', '>', $now)->sum('quantity');
                $available = (int) $category->capacity - (int) $category->issued_quantity - $activeHeld;
                if ($quantity > $available) {
                    abort(409, 'Requested quantity exceeds available category inventory.');
                }

                $requestedExpiry = $now->modify('+'.(int) $event->hold_duration_seconds.' seconds');
                $expiresAt = $requestedExpiry < $eventStart ? $requestedExpiry : $eventStart;
                if ($expiresAt <= $now) {
                    abort(409, 'The configured hold cannot extend beyond the event start.');
                }
                $this->currency->lockForFirstObligation($eventId, 'inventory_hold');
                $hold = [
                    'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'category_id' => $categoryId,
                    'category_name' => (string) $category->name, 'organisation_id' => (string) $event->organisation_id,
                    'customer_actor_id' => $customerActorId, 'quantity' => $quantity,
                    'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                    'status' => 'held', 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
                ];
                $this->db()->table('community_services.event_inventory_holds')->insert($hold);
                $this->db()->table('community_services.event_draft_audit')->insert([
                    'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $customerActorId,
                    'action' => 'inventory_hold_created', 'version' => (int) $event->version, 'created_at' => $now,
                ]);
                $this->db()->afterCommit(static function () use ($hold, $correlationId): void {
                    app(AuditServiceInterface::class)->record(new AuditEventData(
                        action: 'events.inventory_hold.created', actorType: 'user', resourceType: 'event_inventory_hold',
                        correlationId: $correlationId, outcome: 'success', actorId: $hold['customer_actor_id'],
                        resourceId: $hold['id'], afterState: [
                            'event_id' => $hold['event_id'], 'category_id' => $hold['category_id'],
                            'quantity' => $hold['quantity'], 'expires_at' => $hold['expires_at']->format(DATE_ATOM),
                        ],
                    ));
                });

                return $hold;
            }, 3);
        } catch (CurrencyReviewRequired $exception) {
            $this->currency->recordReviewIssue($exception->eventId, $exception->reasonCode, $customerActorId);
            abort(409, 'Event currency is unavailable or changed; operator review is required.');
        }
    }

    /** @return int number of holds transitioned to expired */
    public function expireDueHolds(?string $eventId = null): int
    {
        return $this->db()->transaction(function () use ($eventId): int {
            $query = $this->db()->table('community_services.event_inventory_holds')
                ->where('status', 'held')->where('expires_at', '<=', now());
            if ($eventId !== null) {
                $query->where('event_id', $eventId);
            }
            $ids = $query->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }
            $this->db()->table('community_services.event_inventory_holds')->whereIn('id', $ids)->update([
                'status' => 'expired', 'updated_at' => now(),
            ]);

            return $ids->count();
        });
    }

    private function expireCategoryHoldsDue(string $eventId, string $categoryId, DateTimeImmutable $now): void
    {
        $this->db()->table('community_services.event_inventory_holds')->where('event_id', $eventId)
            ->where('category_id', $categoryId)->where('status', 'held')->where('expires_at', '<=', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);
    }
}
