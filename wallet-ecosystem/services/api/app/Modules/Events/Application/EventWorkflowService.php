<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;
use App\Modules\Events\Domain\Contracts\OrganiserVerificationContract;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Fail-closed event review/publication state transitions. No HTTP surface is registered. */
final class EventWorkflowService
{
    public function __construct(
        private readonly EventWorkflowAuthorizer $authorizer,
        private readonly EventPublicationReadiness $readiness,
        private readonly OrganiserVerificationContract $organiserVerification,
        private readonly ComplimentaryAllocationApprovalContract $complimentaryApproval,
    ) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function submitForReview(string $eventId, string $actorId, int $expectedVersion): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'submit');
            if (! in_array($event->workflow_state, ['draft', 'rejected', 'change_pending'], true)) {
                $this->invalid('workflow_state', 'Only an editable draft can be submitted for review.');
            }

            return [
                'workflow_state' => 'pending_review',
                'event_reviewer_id' => null,
                'reviewed_version' => null,
                'price_approver_id' => null,
                'price_approved_version' => null,
                'rejection_reason' => null,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function approvePrices(string $eventId, string $actorId, int $expectedVersion): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'prices');
            $this->requireReviewable($event);
            $this->requireNonContributor($event, $actorId, 'A contributor to this event version cannot approve its ticket prices.');
            if ($event->event_reviewer_id !== null && $event->event_reviewer_id === $actorId) {
                $this->invalid('actor_id', 'The event reviewer and Finance price approver must be different people.');
            }

            return ['price_approver_id' => $actorId, 'price_approved_version' => (int) $event->version];
        });
    }

    /** @return array<string, mixed> */
    public function approvePublication(string $eventId, string $actorId, int $expectedVersion): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'publication');
            $this->requirePendingReview($event);
            $this->requireNonContributor($event, $actorId, 'A contributor to this event version cannot approve its event review.');
            if ($event->price_approver_id !== null && $event->price_approver_id === $actorId) {
                $this->invalid('actor_id', 'The event reviewer and Finance price approver must be different people.');
            }

            return [
                'workflow_state' => 'approved',
                'event_reviewer_id' => $actorId,
                'reviewed_version' => (int) $event->version,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function publish(string $eventId, string $actorId, int $expectedVersion): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'publish');
            $categories = $this->categoryRows($event->id);
            $freeOnly = $this->isFreeOnly($categories);
            if ($event->workflow_state !== 'approved'
                || (int) $event->reviewed_version !== (int) $event->version
                || $event->event_reviewer_id === null
                || (! $freeOnly && ((int) $event->price_approved_version !== (int) $event->version
                    || $event->price_approver_id === null
                    || $event->event_reviewer_id === $event->price_approver_id))) {
                $this->invalid('workflow_state', 'Current-version publication and Finance price approvals are required.');
            }

            $this->applyComplimentaryApprovalSnapshots($event, $categories);
            $organiserEvidence = $this->organiserVerification->evidenceForEventPublication(
                (string) $event->organisation_id,
                (string) $event->id,
            );
            $organiserEvidence->assertAppliesTo(
                (string) $event->organisation_id,
                (string) $event->id,
                new DateTimeImmutable('now'),
            );
            $draft = (array) $event;
            $this->readiness->assertPublishable($draft, $categories);
            $snapshot = $draft;
            $snapshot['sponsors'] = json_decode((string) $event->sponsors, true, 512, JSON_THROW_ON_ERROR);
            $snapshot['categories'] = $categories;
            $snapshot['organiser_verification_snapshot'] = $organiserEvidence->toSnapshot();
            unset($snapshot['published_snapshot'], $snapshot['rejection_reason']);

            return [
                'workflow_state' => 'published',
                'published_version' => (int) $event->version,
                'published_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                // Keep paid sales closed until a separately authorized operator resumes them.
                'sales_state' => 'paused',
            ];
        });
    }

    /** @return array<string, mixed> */
    public function reject(string $eventId, string $actorId, int $expectedVersion, string $reason): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId, $reason): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'publication');
            $this->requirePendingReview($event);
            $this->requireNonContributor($event, $actorId, 'A contributor to this event version cannot approve its event review.');
            if (trim($reason) === '') {
                $this->invalid('reason', 'A rejection reason is required.');
            }

            return [
                'workflow_state' => 'rejected',
                'event_reviewer_id' => $actorId,
                'reviewed_version' => (int) $event->version,
                'rejection_reason' => mb_substr(trim($reason), 0, 1000),
                'sales_state' => $event->published_version === null ? 'not_published' : 'paused',
            ];
        });
    }

    /** @return array<string, mixed> */
    public function resumeSales(string $eventId, string $actorId, int $expectedVersion): array
    {
        return $this->transition($eventId, $actorId, $expectedVersion, function (object $event) use ($actorId): array {
            $this->authorize($actorId, (string) $event->organisation_id, (string) $event->id, 'resume');
            $canResumePublished = $event->workflow_state === 'published'
                && (int) $event->published_version === (int) $event->version;
            $canResumePriorSnapshot = $event->workflow_state === 'rejected' && $event->published_version !== null;
            if (! $canResumePublished && ! $canResumePriorSnapshot) {
                $this->invalid('workflow_state', 'Only a published version or a rejected edit with a prior published version can resume sales.');
            }

            $snapshot = json_decode((string) $event->published_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $snapshotCategories = $snapshot['categories'] ?? [];
            $this->applyComplimentaryApprovalSnapshots($event, $snapshotCategories);
            $snapshot['categories'] = $snapshotCategories;
            $this->readiness->assertPublishable($snapshot, $snapshotCategories);
            $now = new DateTimeImmutable('now');
            $salesStart = new DateTimeImmutable((string) $snapshot['sales_start_at']);
            $salesEnd = new DateTimeImmutable((string) $snapshot['sales_end_at']);
            if ($now < $salesStart || $now >= $salesEnd) {
                $this->invalid('sales_state', 'Sales can resume only inside the approved sales window.');
            }

            return ['sales_state' => 'active'];
        });
    }

    /** @return array<string, mixed> */
    private function transition(string $eventId, string $actorId, int $expectedVersion, callable $change): array
    {
        return $this->db()->transaction(function () use ($eventId, $actorId, $expectedVersion, $change): array {
            $event = $this->db()->table('community_services.event_drafts')->where('id', $eventId)->lockForUpdate()->first();
            abort_if($event === null, 404);
            if (($event->lifecycle_state ?? 'active') !== 'active') {
                abort(409, 'A cancelled event cannot transition workflow or sales state.');
            }
            if ((int) $event->version !== $expectedVersion) {
                abort(409, 'Event version changed; reload before continuing.');
            }

            $fields = $change($event);
            $action = match (true) {
                array_key_exists('workflow_state', $fields) => 'workflow_'.$fields['workflow_state'],
                array_key_exists('sales_state', $fields) => 'workflow_sales_'.$fields['sales_state'],
                array_key_exists('price_approver_id', $fields) => 'workflow_prices_approved',
                default => throw new \LogicException('Workflow transition has no audit action.'),
            };
            $this->db()->table('community_services.event_drafts')->where('id', $eventId)->update($fields + ['updated_at' => now()]);
            $this->db()->table('community_services.event_draft_audit')->insert([
                'id' => (string) Uuid::uuid7(),
                'event_id' => $eventId,
                'actor_id' => $actorId,
                'action' => $action,
                'version' => $expectedVersion,
                'created_at' => now(),
            ]);

            return $this->get($eventId);
        });
    }

    /** @return array<string, mixed> */
    private function get(string $eventId): array
    {
        $event = (array) $this->db()->table('community_services.event_drafts')->where('id', $eventId)->first();
        $event['sponsors'] = json_decode((string) $event['sponsors'], true, 512, JSON_THROW_ON_ERROR);
        $event['published_snapshot'] = $event['published_snapshot'] === null
            ? null
            : json_decode((string) $event['published_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $event['categories'] = $this->categoryRows($eventId);

        return $event;
    }

    /** @return array<int, array<string, mixed>> */
    private function categoryRows(string $eventId): array
    {
        return $this->db()->table('community_services.event_draft_categories')->where('event_id', $eventId)
            ->orderBy('name')->get([
                'id', 'name', 'capacity', 'price_minor', 'allocation_type', 'complimentary_quota', 'max_per_person',
            ])->map(static fn (object $row): array => (array) $row)->all();
    }

    private function authorize(string $actorId, string $organisationId, string $eventId, string $operation): void
    {
        switch ($operation) {
            case 'submit':
                $this->authorizer->assertCanSubmit($actorId, $organisationId, $eventId);
                break;
            case 'publication':
                $this->authorizer->assertCanApprovePublication($actorId, $organisationId, $eventId);
                break;
            case 'prices':
                $this->authorizer->assertCanApprovePrices($actorId, $organisationId, $eventId);
                break;
            case 'publish':
                $this->authorizer->assertCanPublish($actorId, $organisationId, $eventId);
                break;
            case 'resume':
                $this->authorizer->assertCanResumeSales($actorId, $organisationId, $eventId);
                break;
            default:
                throw new \LogicException('Unknown event workflow operation.');
        }
    }

    private function requirePendingReview(object $event): void
    {
        if ($event->workflow_state !== 'pending_review') {
            $this->invalid('workflow_state', 'The current event version is not awaiting review.');
        }
    }

    private function requireReviewable(object $event): void
    {
        if (! in_array($event->workflow_state, ['pending_review', 'approved'], true)
            || ($event->workflow_state === 'approved' && (int) $event->reviewed_version !== (int) $event->version)) {
            $this->invalid('workflow_state', 'The current event version is not awaiting review or price approval.');
        }
    }

    private function requireNonContributor(object $event, string $actorId, string $message): void
    {
        $isContributor = $this->db()->table('community_services.event_draft_version_contributors')
            ->where('event_id', $event->id)->where('version', $event->version)->where('actor_id', $actorId)->exists();
        if ($isContributor) {
            $this->invalid('actor_id', $message);
        }
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function isFreeOnly(array $categories): bool
    {
        if ($categories === []) {
            return false;
        }

        foreach ($categories as $category) {
            if (! in_array((string) ($category['allocation_type'] ?? 'paid'), ['free', 'complimentary'], true)
                || (int) ($category['price_minor'] ?? -1) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function applyComplimentaryApprovalSnapshots(object $event, array &$categories): void
    {
        foreach ($categories as &$category) {
            if (($category['allocation_type'] ?? null) === 'complimentary') {
                $evidence = $this->complimentaryApproval->approvalEvidence(
                    (string) $event->organisation_id,
                    (string) $event->id,
                    (string) $category['id'],
                );
                if (! $evidence instanceof ComplimentaryAllocationApprovalEvidence) {
                    $this->invalid('complimentary_approval', 'Complimentary allocation approval evidence is unavailable.');
                }
                $evidence->assertAppliesTo(
                    (string) $event->organisation_id,
                    (string) $event->id,
                    (string) $category['id'],
                    (int) ($category['complimentary_quota'] ?? 0),
                    new DateTimeImmutable('now'),
                );
                $category['complimentary_approval_snapshot'] = $evidence->toSnapshot();
            }
        }
        unset($category);
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
