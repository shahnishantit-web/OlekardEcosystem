<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\CommunityFuneral\Application\Jobs\DispatchFuneralPrivateNotice;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class FuneralAssistanceService
{
    public function defaultAmountMinor(): int
    {
        return (int) config('community_funeral.default_amount_minor', 2000);
    }

    public function defaultCurrency(): string
    {
        return (string) config('community_funeral.default_currency', 'BWP');
    }

    public function __construct(private readonly OrganisationMembershipVerifier $organisationMembership) {}

    private function db(): Connection
    {
        return DB::connection('community_services');
    }

    /** @return array<string, mixed> */
    public function createCommunity(string $organisationId, string $displayName, string $actorId): array
    {
        $this->organisationMembership->assertActiveMembership($actorId, $organisationId);
        $id = (string) Uuid::uuid7();
        $now = now();
        $this->db()->table('community_services.funeral_communities')->insert([
            'id' => $id,
            'organisation_id' => $organisationId,
            'display_name' => $displayName,
            'status' => 'active',
            'policy_version' => 'lc06-v1.1',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) $this->db()->table('community_services.funeral_communities')->where('id', $id)->first();
    }

    public function addCommunityStaff(string $communityId, string $staffActorId, string $role, string $ownerId): void
    {
        $community = $this->community($communityId);
        $this->organisationMembership->assertActiveMembership($ownerId, (string) $community->organisation_id);
        if ($community->created_by !== $ownerId || ! in_array($role, ['manager', 'reviewer'], true)) {
            abort(403, 'Only the community owner can assign an authorised community role.');
        }
        $this->organisationMembership->assertActiveMembership($staffActorId, (string) $community->organisation_id);
        if (! DB::connection('identity')->table('identity.users')->where('id', $staffActorId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['actor_id' => 'Staff actor is not active.']);
        }
        $now = now();
        $this->db()->table('community_services.funeral_community_staff')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'community_id' => $communityId, 'actor_id' => $staffActorId,
            'role' => $role, 'status' => 'active', 'granted_by' => $ownerId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db()->table('community_services.funeral_community_staff')
            ->where('community_id', $communityId)->where('actor_id', $staffActorId)
            ->update(['role' => $role, 'status' => 'active', 'granted_by' => $ownerId, 'updated_at' => $now]);
    }

    public function reviewHousehold(string $householdId, string $decision, ?string $exemptionReference, string $reviewerId): void
    {
        $household = $this->thisHousehold($householdId);
        $community = $this->community((string) $household->community_id);
        $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
        if ($household->created_by === $reviewerId || ! in_array($decision, ['eligible', 'exempt'], true)) {
            throw ValidationException::withMessages(['household' => 'A separate reviewer must approve eligibility or exemption.']);
        }
        if ($decision === 'exempt' && ($exemptionReference === null || trim($exemptionReference) === '')) {
            throw ValidationException::withMessages(['exemption_reference' => 'An approved exemption reference is required.']);
        }
        $this->db()->table('community_services.funeral_households')->where('id', $householdId)->update([
            'status' => $decision, 'exemption_reference' => $decision === 'exempt' ? $exemptionReference : null,
            'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit('household.reviewed', $reviewerId, 'household', $householdId, (string) $household->status, $decision);
    }

    /** @return array<string, mixed> */
    public function createHousehold(string $communityId, string $privateReference, string $actorId): array
    {
        $community = $this->community($communityId);
        $this->assertCommunityManager($communityId, $actorId, (string) $community->created_by);
        $id = (string) Uuid::uuid7();
        $now = now();
        $this->db()->table('community_services.funeral_households')->insert([
            'id' => $id,
            'community_id' => $communityId,
            'private_reference' => $privateReference,
            'status' => 'pending_review',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) $this->thisHousehold($id);
    }

    /** @return array<string, mixed> */
    public function addRepresentative(string $householdId, string $representativeActorId, ?string $evidenceReference, string $actorId): array
    {
        $household = $this->thisHousehold($householdId);
        $community = $this->community((string) $household->community_id);
        $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
        $identityUser = DB::connection('identity')->table('identity.users')->where('id', $representativeActorId)->where('status', 'active')->exists();
        if (! $identityUser) {
            throw ValidationException::withMessages(['representative_actor_id' => 'Representative actor is not active.']);
        }
        $id = (string) Uuid::uuid7();
        $now = now();
        $this->db()->table('community_services.funeral_representatives')->insert([
            'id' => $id,
            'household_id' => $householdId,
            'actor_id' => $representativeActorId,
            'status' => 'unverified',
            'evidence_reference' => $evidenceReference,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'household_id' => $householdId, 'status' => 'unverified'];
    }

    public function verifyRepresentative(string $householdId, string $representativeId, string $reviewerId): void
    {
        $household = $this->thisHousehold($householdId);
        $community = $this->community((string) $household->community_id);
        $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
        $rep = $this->db()->table('community_services.funeral_representatives')->where('id', $representativeId)->where('household_id', $householdId)->first();
        if ($rep === null || $rep->actor_id === $reviewerId || $household->created_by === $reviewerId || trim((string) $rep->evidence_reference) === '') {
            throw ValidationException::withMessages(['representative' => 'A separate reviewer must verify the household representative.']);
        }
        $this->db()->table('community_services.funeral_representatives')->where('id', $representativeId)->update([
            'status' => 'verified', 'verified_by' => $reviewerId, 'verified_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit('representative.verified', $reviewerId, 'representative', $representativeId, (string) $rep->status, 'verified');
    }

    public function revokeRepresentative(string $householdId, string $representativeId, string $reviewerId): void
    {
        $household = $this->thisHousehold($householdId);
        $community = $this->community((string) $household->community_id);
        $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
        $rep = $this->db()->table('community_services.funeral_representatives')->where('id', $representativeId)->where('household_id', $householdId)->first();
        if ($rep === null || $rep->actor_id === $reviewerId) {
            throw ValidationException::withMessages(['representative' => 'A separate reviewer must revoke the household representative.']);
        }
        $this->db()->table('community_services.funeral_representatives')->where('id', $representativeId)->update([
            'status' => 'revoked', 'updated_at' => now(),
        ]);
        $this->audit('representative.revoked', $reviewerId, 'representative', $representativeId, (string) $rep->status, 'revoked');
    }

    /** @return array<string, mixed> */
    public function createCollection(string $communityId, string $beneficiaryId, ?string $evidenceReference, string $actorId): array
    {
        $community = $this->community($communityId);
        $this->assertCommunityManager($communityId, $actorId, (string) $community->created_by);
        $id = (string) Uuid::uuid7();
        $now = now();
        $this->db()->table('community_services.funeral_collections')->insert([
            'id' => $id, 'community_id' => $communityId, 'status' => 'under_review',
            'beneficiary_id' => $beneficiaryId, 'evidence_reference' => $evidenceReference,
            'amount_minor' => $this->defaultAmountMinor(), 'currency_code' => $this->defaultCurrency(),
            'terms_version' => 'lc06-v1.1', 'created_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return (array) $this->collection($id);
    }

    public function changeBeneficiary(string $collectionId, string $beneficiaryId, string $actorId): void
    {
        $this->db()->transaction(function () use ($collectionId, $beneficiaryId, $actorId): void {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || ! in_array($collection->status, ['under_review', 'approved'], true)) {
                throw ValidationException::withMessages(['collection' => 'This collection cannot change beneficiary.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
            $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->update([
                'beneficiary_id' => $beneficiaryId,
                'status' => 'under_review',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
            $this->audit('collection.beneficiary_changed', $actorId, 'collection', $collectionId, (string) $collection->status, 'under_review');
        });
    }

    public function approveCollection(string $collectionId, string $reviewerId): void
    {
        $this->db()->transaction(function () use ($collectionId, $reviewerId): void {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'under_review' || $collection->created_by === $reviewerId || trim((string) $collection->evidence_reference) === '') {
                throw ValidationException::withMessages(['collection' => 'Independent review is required before approval.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
            $this->assertVerifiedBeneficiary((string) $collection->beneficiary_id, (string) $community->organisation_id);
            $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->update([
                'status' => 'approved', 'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('collection.approved', $reviewerId, 'collection', $collectionId, 'under_review', 'approved');
        });
    }

    /** @return array{snapshot_id:string, eligible_count:int, exempt_count:int, obligation_count:int} */
    public function activateCollection(string $collectionId, string $actorId): array
    {
        return $this->db()->transaction(function () use ($collectionId, $actorId): array {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'approved') {
                throw ValidationException::withMessages(['collection' => 'Only an independently approved collection can be activated.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityReviewer((string) $community->id, $actorId, (string) $community->created_by);
            $this->assertVerifiedBeneficiary((string) $collection->beneficiary_id, (string) $community->organisation_id);

            $households = $this->db()->table('community_services.funeral_households')
                ->where('community_id', $community->id)->whereIn('status', ['eligible', 'exempt'])->orderBy('id')->get();
            if ($households->isEmpty()) {
                throw ValidationException::withMessages(['households' => 'No reviewed eligible or exempt households are available.']);
            }
            foreach ($households as $household) {
                if ($household->status === 'eligible' && ! $this->db()->table('community_services.funeral_representatives')
                    ->where('household_id', $household->id)->where('status', 'verified')->exists()) {
                    throw ValidationException::withMessages(['households' => 'Every participating household requires a verified representative.']);
                }
            }

            $snapshotId = (string) Uuid::uuid7();
            $version = (int) $this->db()->table('community_services.funeral_eligibility_snapshots')->where('collection_id', $collectionId)->max('version') + 1;
            $eligible = 0;
            $exempt = 0;
            $members = [];
            foreach ($households as $household) {
                $isEligible = $household->status === 'eligible';
                $eligible += $isEligible ? 1 : 0;
                $exempt += $isEligible ? 0 : 1;
                $members[] = [
                    'id' => (string) Uuid::uuid7(), 'snapshot_id' => $snapshotId, 'household_id' => $household->id,
                    'eligibility_status' => $isEligible ? 'eligible' : 'exempt',
                    'decision_reference' => $household->exemption_reference,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $digest = hash('sha256', json_encode(array_map(static fn (array $m): array => [$m['household_id'], $m['eligibility_status'], $m['decision_reference']], $members), JSON_THROW_ON_ERROR));
            $now = now();
            $this->db()->table('community_services.funeral_eligibility_snapshots')->insert([
                'id' => $snapshotId, 'collection_id' => $collectionId, 'version' => $version,
                'terms_version' => $collection->terms_version, 'eligible_count' => $eligible, 'exempt_count' => $exempt,
                'membership_digest' => $digest, 'activated_by' => $actorId, 'frozen_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db()->table('community_services.funeral_snapshot_households')->insert($members);
            $obligationCount = 0;
            $noticeIds = [];
            foreach ($members as $member) {
                if ($member['eligibility_status'] !== 'eligible') {
                    continue;
                }
                $this->db()->table('community_services.funeral_obligations')->insert([
                    'id' => (string) Uuid::uuid7(), 'collection_id' => $collectionId,
                    'snapshot_household_id' => $member['id'], 'household_id' => $member['household_id'],
                    'amount_minor' => $this->defaultAmountMinor(), 'currency_code' => $this->defaultCurrency(),
                    'status' => 'unpaid', 'fulfilment_status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $obligationCount++;
                $recipients = $this->db()->table('community_services.funeral_representatives')
                    ->where('household_id', $member['household_id'])->where('status', 'verified')->pluck('actor_id');
                foreach ($recipients as $recipientId) {
                    $noticeId = (string) Uuid::uuid7();
                    $this->db()->table('community_services.funeral_private_notices')->insertOrIgnore([
                        'id' => $noticeId, 'snapshot_household_id' => $member['id'],
                        'recipient_actor_id' => $recipientId, 'status' => 'pending',
                        'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $noticeIds[] = $noticeId;
                }
            }
            $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->update([
                'status' => 'active', 'snapshot_id' => $snapshotId, 'opened_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit('collection.activated', $actorId, 'collection', $collectionId, 'approved', 'active');
            foreach ($noticeIds as $noticeId) {
                $this->db()->afterCommit(static function () use ($noticeId): void {
                    try {
                        DispatchFuneralPrivateNotice::dispatch($noticeId);
                    } catch (\Throwable) {
                        // The durable notice remains pending for funeral:notices-retry.
                        Log::warning('community_funeral.private_notice_pending', ['notice_id' => $noticeId]);
                    }
                });
            }

            return ['snapshot_id' => $snapshotId, 'eligible_count' => $eligible, 'exempt_count' => $exempt, 'obligation_count' => $obligationCount];
        });
    }

    /** @return list<array<string, mixed>> */
    public function obligationsForActor(string $actorId): array
    {
        return $this->db()->table('community_services.funeral_obligations as o')
            ->join('community_services.funeral_collections as c', 'c.id', '=', 'o.collection_id')
            ->join('community_services.funeral_representatives as r', 'r.household_id', '=', 'o.household_id')
            ->where('r.actor_id', $actorId)->where('r.status', 'verified')
            ->whereIn('c.status', ['active', 'closed'])
            ->select(['o.id', 'o.amount_minor', 'o.currency_code', 'o.status', 'o.fulfilment_status', 'c.id as collection_id', 'c.status as collection_status'])
            ->orderByDesc('c.opened_at')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function closeCollection(string $collectionId, string $actorId): void
    {
        $this->db()->transaction(function () use ($collectionId, $actorId): void {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'active') {
                throw ValidationException::withMessages(['collection' => 'Only an active collection can be closed.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
            $now = now();
            $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->update(['status' => 'closed', 'closed_at' => $now, 'updated_at' => $now]);
            $this->db()->table('community_services.funeral_closures')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'collection_id' => $collectionId, 'closed_by' => $actorId,
                'closed_at' => $now, 'payout_status' => 'blocked', 'settlement_status' => 'unresolved',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->refreshAggregate($collectionId);
            $this->audit('collection.closed', $actorId, 'collection', $collectionId, 'active', 'closed');
        });
    }

    /** @return array<string, int|string> */
    public function aggregateReport(string $collectionId, string $actorId): array
    {
        $collection = $this->collection($collectionId);
        $community = $this->community((string) $collection->community_id);
        $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);

        return $this->refreshAggregate($collectionId);
    }

    public function requestCarryForward(string $collectionId, string $reference, string $actorId): void
    {
        $this->db()->transaction(function () use ($collectionId, $reference, $actorId): void {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'closed') {
                throw ValidationException::withMessages(['collection' => 'Only a closed collection can request a carry-forward.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
            $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->update([
                'carry_forward_reference' => trim($reference),
                'carry_forward_requested_by' => $actorId,
                'carry_forward_requested_at' => now(),
                'carry_forward_approved_by' => null,
                'carry_forward_approved_at' => null,
                'updated_at' => now(),
            ]);
            $this->audit('closure.carry_forward_requested', $actorId, 'collection', $collectionId, 'closed', 'awaiting_approval');
        });
    }

    public function approveCarryForward(string $collectionId, string $reviewerId): void
    {
        $this->db()->transaction(function () use ($collectionId, $reviewerId): void {
            $collection = $this->collection($collectionId);
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
            $closure = $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->lockForUpdate()->first();
            if ($collection->status !== 'closed' || $closure === null || trim((string) $closure->carry_forward_reference) === '' || $closure->carry_forward_requested_by === $reviewerId) {
                throw ValidationException::withMessages(['carry_forward' => 'A separate reviewer must approve the carry-forward record.']);
            }
            $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->update([
                'carry_forward_approved_by' => $reviewerId, 'carry_forward_approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('closure.carry_forward_approved', $reviewerId, 'collection', $collectionId, 'awaiting_approval', 'approved');
        });
    }

    public function archiveCollection(string $collectionId, string $actorId): void
    {
        $this->db()->transaction(function () use ($collectionId, $actorId): void {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'closed') {
                throw ValidationException::withMessages(['collection' => 'Only a closed collection can be archived.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
            $report = $this->refreshAggregate($collectionId);
            $closure = $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->first();
            if ((int) $report['unresolved_liability_count'] > 0 && ($closure === null || $closure->carry_forward_approved_by === null)) {
                throw ValidationException::withMessages(['liabilities' => 'Unresolved liabilities require an independently approved carry-forward record.']);
            }
            $now = now();
            $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->update([
                'archived_at' => $now, 'updated_at' => $now,
            ]);
            $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->update(['status' => 'archived', 'updated_at' => $now]);
            $this->audit('collection.archived', $actorId, 'collection', $collectionId, 'closed', 'archived');
        });
    }

    /** Persist one confirmed Payment event; closed collections are kept as unresolved late reviews. */
    public function recordPaymentConfirmation(array $payload): void
    {
        $obligationId = (string) ($payload['purpose_reference'] ?? '');
        $intentId = (string) ($payload['payment_intent_id'] ?? '');
        if (! Uuid::isValid($obligationId) || ! Uuid::isValid($intentId)) {
            return;
        }
        $this->db()->transaction(function () use ($payload, $obligationId, $intentId): void {
            $initial = $this->db()->table('community_services.funeral_obligations')->where('id', $obligationId)->first();
            if ($initial === null) {
                return;
            }
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $initial->collection_id)->lockForUpdate()->first();
            $obligation = $this->db()->table('community_services.funeral_obligations')->where('id', $obligationId)->lockForUpdate()->first();
            if ($collection === null || $obligation === null) {
                return;
            }
            $community = $this->community((string) $collection->community_id);
            $intent = DB::connection('payment')->table('payment.payment_intents')->where('id', $intentId)->first();
            $attempt = DB::connection('payment')->table('payment.payment_attempts')
                ->where('id', (string) ($payload['payment_attempt_id'] ?? ''))->where('payment_intent_id', $intentId)->first();
            if ($intent === null || $attempt === null || $intent->purpose_type !== 'funeral_contribution'
                || $intent->purpose_reference !== $obligationId
                || $intent->organisation_id !== $community->organisation_id
                || $intent->accounting_status !== 'ledger_posted'
                || ! in_array($intent->status, ['succeeded', 'refunding', 'partially_refunded', 'refunded'], true)
                || $attempt->status !== 'succeeded'
                || (string) ($payload['organisation_id'] ?? '') !== $intent->organisation_id
                || (string) ($payload['payer_actor_id'] ?? '') !== $intent->payer_actor_id
                || (int) ($payload['amount_minor'] ?? 0) !== (int) $intent->amount_minor
                || (string) ($payload['currency_code'] ?? '') !== (string) $intent->currency_code
                || (int) ($payload['fee_minor'] ?? 0) !== (int) ($attempt->fee_minor ?? 0)) {
                throw new \RuntimeException('Payment confirmation does not match authoritative Payment records.');
            }
            $payerEligible = $this->db()->table('community_services.funeral_representatives')
                ->where('household_id', $obligation->household_id)->where('actor_id', $intent->payer_actor_id)
                ->where('status', 'verified')->exists();
            $matches = (int) ($payload['amount_minor'] ?? 0) === (int) $obligation->amount_minor
                && (string) ($payload['currency_code'] ?? '') === (string) $obligation->currency_code;
            $onTime = $collection->status === 'active' && $matches && $payerEligible && $obligation->status === 'unpaid';
            $duplicateFulfilment = $obligation->status === 'confirmed' || $obligation->fulfilment_status === 'fulfilled';
            $domainStatus = $onTime ? 'confirmed' : 'late_review';
            $now = now();
            $inserted = $this->db()->table('community_services.funeral_payment_receipts')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(), 'obligation_id' => $obligationId, 'payment_intent_id' => $intentId,
                'gross_amount_minor' => (int) ($payload['amount_minor'] ?? 0), 'fee_amount_minor' => max(0, (int) ($payload['fee_minor'] ?? 0)),
                'currency_code' => (string) ($payload['currency_code'] ?? ''), 'domain_status' => $domainStatus,
                'correlation_id' => isset($payload['correlation_id']) ? (string) $payload['correlation_id'] : null,
                'confirmed_at' => $payload['occurred_at'] ?? $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted !== 1) {
                return;
            }
            if ($onTime) {
                $this->db()->table('community_services.funeral_obligations')->where('id', $obligationId)->update([
                    'status' => 'confirmed', 'fulfilment_status' => 'fulfilled', 'payment_intent_id' => $intentId,
                    'fulfilled_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit('obligation.fulfilled', (string) $intent->payer_actor_id, 'obligation', $obligationId, (string) $obligation->fulfilment_status, 'fulfilled');
            } else {
                $reason = ! $matches ? 'payment_mismatch' : (! $payerEligible ? 'payer_access_changed' : ($collection->status !== 'active' ? 'collection_closed' : ($duplicateFulfilment ? 'duplicate_payment' : 'obligation_not_payable')));
                if (! $duplicateFulfilment) {
                    $this->db()->table('community_services.funeral_obligations')->where('id', $obligationId)->update([
                        'status' => 'late_review', 'fulfilment_status' => 'held_for_review', 'payment_intent_id' => $intentId, 'updated_at' => $now,
                    ]);
                }
                $this->db()->table('community_services.funeral_late_reviews')->insertOrIgnore([
                    'id' => (string) Uuid::uuid7(), 'collection_id' => $collection->id, 'obligation_id' => $obligationId,
                    'payment_intent_id' => $intentId, 'reason' => $reason, 'status' => 'unresolved', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit('obligation.late_review', (string) $intent->payer_actor_id, 'obligation', $obligationId, (string) $obligation->fulfilment_status, 'held_for_review');
            }
            if ($collection->status === 'closed') {
                $this->refreshAggregate((string) $collection->id);
            }
        });
    }

    /** @return array<string, int|string> */
    private function refreshAggregate(string $collectionId): array
    {
        $base = $this->db()->table('community_services.funeral_payment_receipts as r')
            ->join('community_services.funeral_obligations as o', 'o.id', '=', 'r.obligation_id')
            ->where('o.collection_id', $collectionId);
        $totals = (clone $base)->selectRaw('COALESCE(SUM(r.gross_amount_minor),0) AS gross, COALESCE(SUM(r.fee_amount_minor),0) AS fees')
            ->first();
        $accepted = (clone $base)->where('r.domain_status', 'confirmed')
            ->selectRaw('COALESCE(SUM(r.gross_amount_minor),0) AS gross, COALESCE(SUM(r.fee_amount_minor),0) AS fees')->first();
        $intentIds = (clone $base)->pluck('r.payment_intent_id')->all();
        $acceptedIntentIds = (clone $base)->where('r.domain_status', 'confirmed')->pluck('r.payment_intent_id')->all();
        $refunds = $intentIds === [] ? 0 : (int) DB::connection('payment')->table('payment.payment_refunds')
            ->whereIn('payment_intent_id', $intentIds)->whereIn('status', ['confirmed', 'succeeded'])->sum('amount_minor');
        $acceptedRefunds = $acceptedIntentIds === [] ? 0 : (int) DB::connection('payment')->table('payment.payment_refunds')
            ->whereIn('payment_intent_id', $acceptedIntentIds)->whereIn('status', ['confirmed', 'succeeded'])->sum('amount_minor');
        $pendingRefundCount = $intentIds === [] ? 0 : (int) DB::connection('payment')->table('payment.payment_refunds')
            ->whereIn('payment_intent_id', $intentIds)->whereIn('status', ['requested', 'pending_approval', 'processing', 'submitted', 'pending', 'unknown'])->count();
        $obligationIds = $this->db()->table('community_services.funeral_obligations')->where('collection_id', $collectionId)->pluck('id')->all();
        $pendingPaymentCount = $obligationIds === [] ? 0 : (int) DB::connection('payment')->table('payment.payment_intents')
            ->where('purpose_type', 'funeral_contribution')->whereIn('purpose_reference', $obligationIds)
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'processing', 'awaiting_action', 'requires_confirmation'])
                    ->orWhere('accounting_status', 'pending_ledger');
            })->count();
        $lateReviewCount = (int) $this->db()->table('community_services.funeral_late_reviews')
            ->where('collection_id', $collectionId)->where('status', 'unresolved')->count();
        $lateGross = (int) (clone $base)->where('r.domain_status', 'late_review')->sum('r.gross_amount_minor');
        $gross = (int) ($totals->gross ?? 0);
        $fees = (int) ($totals->fees ?? 0);
        $net = (int) ($accepted->gross ?? 0) - (int) ($accepted->fees ?? 0) - $acceptedRefunds;
        $payoutLiabilityOpen = $net > 0 ? 1 : 0;
        $unresolved = $pendingPaymentCount + $pendingRefundCount + $lateReviewCount + $payoutLiabilityOpen;
        $result = ['collection_id' => $collectionId, 'confirmed_gross_minor' => $gross, 'fee_total_minor' => $fees,
            'confirmed_refunds_minor' => $refunds, 'net_support_minor' => $net, 'late_review_gross_minor' => $lateGross, 'unresolved_liability_count' => $unresolved,
            'pending_payment_count' => $pendingPaymentCount, 'pending_refund_count' => $pendingRefundCount,
            'late_review_count' => $lateReviewCount, 'payout_liability_open' => $payoutLiabilityOpen,
            'settlement_status' => 'unresolved', 'payout_status' => 'blocked'];
        $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->update([
            'confirmed_gross_minor' => $gross, 'fee_total_minor' => $fees, 'confirmed_refunds_minor' => $refunds,
            'net_support_minor' => $net, 'unresolved_liability_count' => $unresolved, 'updated_at' => now(),
            'late_review_gross_minor' => $lateGross,
        ]);

        return $result;
    }

    private function assertVerifiedBeneficiary(string $beneficiaryId, string $organisationId): void
    {
        $beneficiary = PayoutBeneficiaryModel::query()->where('id', $beneficiaryId)->first();
        if ($beneficiary === null || $beneficiary->status !== PayoutBeneficiaryStatus::Verified || $beneficiary->organisation_id !== $organisationId) {
            throw ValidationException::withMessages(['beneficiary_id' => 'Beneficiary must be verified and belong to this organisation.']);
        }
    }

    private function audit(string $action, ?string $actorId, string $resourceType, string $resourceId, ?string $from, string $to): void
    {
        $auditId = (string) Uuid::uuid7();
        $this->db()->table('community_services.funeral_audit_events')->insert([
            'id' => $auditId, 'action' => $action, 'actor_id' => $actorId,
            'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'from_status' => $from, 'to_status' => $to, 'occurred_at' => now(),
        ]);
        $this->db()->afterCommit(static function () use ($auditId, $action, $actorId, $resourceType, $resourceId, $from, $to): void {
            app(AuditServiceInterface::class)->record(new AuditEventData(
                action: 'community_funeral.'.str_replace('.', '_', $action),
                actorType: $actorId === null ? 'system' : 'user',
                resourceType: $resourceType,
                correlationId: $auditId,
                outcome: 'success',
                actorId: $actorId,
                resourceId: $resourceId,
                beforeState: $from === null ? null : ['status' => $from],
                afterState: ['status' => $to],
            ));
        });
    }

    private function assertCommunityManager(string $communityId, string $actorId, string $ownerId): void
    {
        $community = $this->community($communityId);
        $this->organisationMembership->assertActiveMembership($actorId, (string) $community->organisation_id);
        if ($ownerId !== $actorId && ! $this->db()->table('community_services.funeral_community_staff')->where('community_id', $communityId)->where('actor_id', $actorId)->where('role', 'manager')->where('status', 'active')->exists()) {
            abort(403, 'Community access is not authorised.');
        }
    }

    private function assertCommunityReviewer(string $communityId, string $actorId, string $ownerId): void
    {
        $community = $this->community($communityId);
        $this->organisationMembership->assertActiveMembership($actorId, (string) $community->organisation_id);
        if ($ownerId !== $actorId && ! $this->db()->table('community_services.funeral_community_staff')->where('community_id', $communityId)->where('actor_id', $actorId)->whereIn('role', ['manager', 'reviewer'])->where('status', 'active')->exists()) {
            abort(403, 'Community review access is not authorised.');
        }
    }

    private function community(string $id): object
    {
        $community = $this->db()->table('community_services.funeral_communities')->where('id', $id)->first();
        abort_if($community === null, 404);

        return $community;
    }

    private function collection(string $id): object
    {
        $collection = $this->db()->table('community_services.funeral_collections')->where('id', $id)->first();
        abort_if($collection === null, 404);

        return $collection;
    }

    private function thisHousehold(string $id): object
    {
        $household = $this->db()->table('community_services.funeral_households')->where('id', $id)->first();
        abort_if($household === null, 404);

        return $household;
    }
}
