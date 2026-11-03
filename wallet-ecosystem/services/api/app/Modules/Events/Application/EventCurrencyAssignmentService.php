<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Exceptions\CurrencyReviewRequired;
use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyDefinitionGuard;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Finance-authorized event currency assignment and first-obligation precision lock. */
final class EventCurrencyAssignmentService
{
    public function __construct(
        private readonly ScopedEventAuthorization $authorization,
        private readonly CurrencyDefinitionGuard $currencyDefinitions,
        private readonly OrganisationMembershipAuthority $membership,
    ) {}

    /** @return array<string, mixed> */
    public function assign(
        string $eventId,
        string $actorId,
        string $currencyCode,
        string $approvalReference,
        string $policyVersion,
        string $correlationId,
    ): array {
        $currencyCode = strtoupper(trim($currencyCode));
        if (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1 || trim($approvalReference) === '' || trim($policyVersion) === '') {
            throw ValidationException::withMessages(['currency' => 'Currency, Finance approval reference, and policy version are required.']);
        }

        return DB::connection('community_services')->transaction(function () use (
            $eventId, $actorId, $currencyCode, $approvalReference, $policyVersion, $correlationId,
        ): array {
            $db = DB::connection('community_services');
            $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $this->membership->assertActiveMembership($actorId, (string) $event->organisation_id);
            $this->authorization->lockEventAssignment($actorId, (string) $event->organisation_id, $eventId, EventPermissions::CURRENCY_ASSIGN);
            if ($event->currency_locked_at !== null) {
                abort(409, 'Event currency and precision are locked by its first durable order or inventory hold.');
            }
            $currency = $this->currencyDefinitions->lockAndRead($currencyCode, 'community_services');
            if ($currency === null || ! $currency->active) {
                throw ValidationException::withMessages(['currency_code' => 'Currency must be active in the authoritative currency registry.']);
            }

            $active = $db->table('community_services.event_currency_assignments')
                ->where('event_id', $eventId)->whereNull('superseded_at')->lockForUpdate()->first();
            if ($active !== null && $active->currency_code === $currencyCode
                && (int) $active->precision_snapshot === $currency->decimalPlaces) {
                return (array) $active;
            }
            $now = now();
            if ($active !== null) {
                $db->table('community_services.event_currency_assignments')->where('id', $active->id)->update([
                    'superseded_at' => $now, 'updated_at' => $now,
                ]);
            }
            $assignment = [
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId,
                'organisation_id' => (string) $event->organisation_id, 'currency_code' => $currencyCode,
                'precision_snapshot' => $currency->decimalPlaces,
                'currency_definition_updated_at' => $currency->updatedAt,
                'assigned_by_actor_id' => $actorId, 'approval_reference' => trim($approvalReference),
                'policy_version' => trim($policyVersion), 'assigned_at' => $now,
                'superseded_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
            $db->table('community_services.event_currency_assignments')->insert($assignment);
            $db->table('community_services.event_drafts')->where('id', $eventId)->update([
                'currency_code' => $currencyCode,
                'currency_precision_snapshot' => $currency->decimalPlaces,
                'currency_definition_updated_at' => $currency->updatedAt,
                'currency_assignment_id' => $assignment['id'], 'updated_at' => $now,
            ]);
            $db->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $actorId,
                'action' => 'currency_assigned', 'version' => (int) $event->version, 'created_at' => $now,
            ]);
            $db->afterCommit(static function () use ($assignment, $correlationId): void {
                app(AuditServiceInterface::class)->record(new AuditEventData(
                    action: 'events.currency.assigned', actorType: 'user', resourceType: 'event_currency_assignment',
                    correlationId: $correlationId, outcome: 'success', actorId: $assignment['assigned_by_actor_id'],
                    resourceId: $assignment['id'], afterState: [
                        'event_id' => $assignment['event_id'], 'currency_code' => $assignment['currency_code'],
                        'precision_snapshot' => $assignment['precision_snapshot'],
                        'currency_definition_updated_at' => (string) $assignment['currency_definition_updated_at'],
                        'approval_reference' => $assignment['approval_reference'], 'policy_version' => $assignment['policy_version'],
                    ],
                ));
            });

            return $assignment;
        });
    }

    /** Must run within the shared community_services transaction before creating the durable order. */
    public function lockForFirstObligation(string $eventId, string $reason): object
    {
        $db = DB::connection('community_services');
        if ($db->transactionLevel() < 1 || ! in_array($reason, ['inventory_hold', 'durable_order'], true)) {
            throw new \LogicException('Currency locking must share the event transaction and identify its first obligation.');
        }
        $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
        abort_if($event === null, 404, 'Event was not found.');
        $current = $event->currency_code === null
            ? null
            : $this->currencyDefinitions->lockAndRead((string) $event->currency_code, 'community_services');
        $reasonCode = $current === null || ! $current->active
            ? 'CURRENCY_INACTIVE'
            : ($current->decimalPlaces !== (int) $event->currency_precision_snapshot ? 'CURRENCY_PRECISION_CHANGED' : null);
        if ($reasonCode !== null || $event->currency_code === null || $event->currency_assignment_id === null) {
            $reasonCode ??= 'EVENT_CURRENCY_UNASSIGNED';
            throw new CurrencyReviewRequired($eventId, $reasonCode);
        }
        if ($event->currency_locked_at === null) {
            $db->table('community_services.event_drafts')->where('id', $eventId)->update([
                'currency_locked_at' => now(), 'currency_lock_reason' => $reason, 'updated_at' => now(),
            ]);
            $event->currency_locked_at = now();
            $event->currency_lock_reason = $reason;
        }

        return $event;
    }

    public function recordReviewIssue(string $eventId, string $reasonCode, string $detectedByActorId): void
    {
        DB::connection('community_services')->transaction(function () use ($eventId, $reasonCode, $detectedByActorId): void {
            $db = DB::connection('community_services');
            $event = $db->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404, 'Event was not found.');
            $now = now();
            $db->table('community_services.event_currency_review_issues')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'reason_code' => $reasonCode,
                'detected_at' => $now, 'resolved_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($event->sales_state === 'active') {
                $db->table('community_services.event_drafts')->where('id', $eventId)->update([
                    'sales_state' => 'paused', 'updated_at' => $now,
                ]);
            }
            $db->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(), 'event_id' => $eventId, 'actor_id' => $detectedByActorId,
                'action' => 'currency_review_required', 'version' => (int) $event->version, 'created_at' => $now,
            ]);
            $db->afterCommit(static function () use ($eventId, $reasonCode, $detectedByActorId): void {
                app(AuditServiceInterface::class)->record(new AuditEventData(
                    action: 'events.currency.review_required', actorType: 'user', resourceType: 'event',
                    correlationId: (string) Uuid::uuid7(), outcome: 'success', actorId: $detectedByActorId,
                    resourceId: $eventId, afterState: ['reason_code' => $reasonCode, 'sales_state' => 'paused'],
                ));
            });
        });
    }
}
