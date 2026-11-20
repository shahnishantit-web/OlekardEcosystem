<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\CommunityFuneral\Application\Jobs\DispatchFuneralPrivateNotice;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
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

    public function __construct(
        private readonly OrganisationMembershipVerifier $organisationMembership,
        private readonly LedgerModuleContract $ledger,
        private readonly WalletModuleContract $wallet,
    ) {}

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
    public function createCollection(string $communityId, string $beneficiaryId, string $targetHouseholdId, ?string $evidenceReference, string $actorId, ?string $purpose = null): array
    {
        $community = $this->community($communityId);
        $this->assertCommunityManager($communityId, $actorId, (string) $community->created_by);
        $targetHousehold = $this->thisHousehold($targetHouseholdId);
        if ($targetHousehold->community_id !== $communityId || $targetHousehold->status !== 'eligible') {
            throw ValidationException::withMessages(['target_household_id' => 'Select an eligible household in this community.']);
        }
        $id = (string) Uuid::uuid7();
        $now = now();
        $this->db()->table('community_services.funeral_collections')->insert([
            'id' => $id, 'community_id' => $communityId, 'status' => 'under_review', 'target_household_id' => $targetHouseholdId,
            'beneficiary_id' => $beneficiaryId, 'evidence_reference' => $evidenceReference,
            'purpose' => trim((string) $purpose) !== '' ? trim((string) $purpose) : 'Community funeral assistance contribution',
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
                    'amount_minor' => (int) $collection->amount_minor, 'currency_code' => (string) $collection->currency_code,
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
        $rows = $this->db()->table('community_services.funeral_obligations as o')
            ->join('community_services.funeral_collections as c', 'c.id', '=', 'o.collection_id')
            ->join('community_services.funeral_communities as community', 'community.id', '=', 'c.community_id')
            ->join('community_services.funeral_representatives as r', 'r.household_id', '=', 'o.household_id')
            ->where('r.actor_id', $actorId)->where('r.status', 'verified')
            ->whereIn('c.status', ['active', 'closed'])
            ->select([
                'o.id', 'o.amount_minor', 'o.currency_code', 'o.status', 'o.fulfilment_status',
                'c.id as collection_id', 'c.status as collection_status', 'c.purpose as collection_purpose',
                'community.display_name as community_name', 'community.organisation_id',
            ])
            ->orderByDesc('c.opened_at')->get()->map(static fn (object $r): array => (array) $r)->all();

        $organisationNames = $rows === [] ? collect() : DB::connection('identity')->table('identity.organisations')
            ->whereIn('id', array_values(array_unique(array_column($rows, 'organisation_id'))))
            ->pluck('display_name', 'id');

        return array_map(static function (array $row) use ($organisationNames): array {
            $row['organisation_name'] = (string) ($organisationNames[$row['organisation_id']] ?? 'Organisation');
            return $row;
        }, $rows);
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
        $accepted = (clone $base)->where('r.domain_status', 'confirmed')
            ->selectRaw('COALESCE(SUM(r.gross_amount_minor),0) AS gross, COALESCE(SUM(r.fee_amount_minor),0) AS fees')->first();
        $intentIds = (clone $base)->pluck('r.payment_intent_id')->all();
        $acceptedIntentIds = (clone $base)->where('r.domain_status', 'confirmed')->pluck('r.payment_intent_id')->all();
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
        $gross = (int) ($accepted->gross ?? 0);
        $fees = (int) ($accepted->fees ?? 0);
        $disbursed = (int) $this->db()->table('community_services.funeral_disbursements')
            ->where('collection_id', $collectionId)->where('status', 'paid')->sum('amount_minor');
        $net = (int) ($accepted->gross ?? 0) - (int) ($accepted->fees ?? 0) - $acceptedRefunds - $disbursed;
        $payoutLiabilityOpen = $net > 0 ? 1 : 0;
        $unresolved = $pendingPaymentCount + $pendingRefundCount + $lateReviewCount + $payoutLiabilityOpen;
        $confirmedCount = (int) (clone $base)->where('r.domain_status', 'confirmed')->count();
        $disbursement = $this->db()->table('community_services.funeral_disbursements')->where('collection_id', $collectionId)->first();
        $result = ['collection_id' => $collectionId, 'confirmed_gross_minor' => $gross, 'confirmed_contribution_count' => $confirmedCount, 'fee_total_minor' => $fees,
            'confirmed_refunds_minor' => $acceptedRefunds, 'net_support_minor' => $net, 'late_review_gross_minor' => $lateGross, 'unresolved_liability_count' => $unresolved,
            'pending_payment_count' => $pendingPaymentCount, 'pending_refund_count' => $pendingRefundCount,
            'late_review_count' => $lateReviewCount, 'payout_liability_open' => $payoutLiabilityOpen,
            'settlement_status' => $net === 0 && $pendingPaymentCount === 0 && $pendingRefundCount === 0 && $lateReviewCount === 0 ? 'settled' : 'unresolved',
            'payout_status' => $net === 0 && $disbursed > 0 ? 'paid' : (string) ($disbursement->status ?? (($pendingPaymentCount + $pendingRefundCount + $lateReviewCount) > 0 || $net < 1 ? 'blocked' : 'ready')),
            'disbursement' => $disbursement === null ? null : (array) $disbursement];
        $this->db()->table('community_services.funeral_closures')->where('collection_id', $collectionId)->update([
            'confirmed_gross_minor' => $gross, 'fee_total_minor' => $fees, 'confirmed_refunds_minor' => $acceptedRefunds,
            'net_support_minor' => $net, 'unresolved_liability_count' => $unresolved, 'updated_at' => now(),
            'late_review_gross_minor' => $lateGross,
            'payout_status' => $result['payout_status'], 'settlement_status' => $result['settlement_status'],
        ]);

        return $result;
    }

    /** @return array<string, mixed> */
    public function requestDisbursement(string $collectionId, string $actorId): array
    {
        $this->assertLocalPayoutEnabled();
        return $this->db()->transaction(function () use ($collectionId, $actorId): array {
            $collection = $this->db()->table('community_services.funeral_collections')->where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null || $collection->status !== 'closed') {
                throw ValidationException::withMessages(['collection' => 'Only a closed collection can request beneficiary disbursement.']);
            }
            $community = $this->community((string) $collection->community_id);
            $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
            $report = $this->refreshAggregate($collectionId);
            if ((int) $report['pending_payment_count'] > 0 || (int) $report['pending_refund_count'] > 0 || (int) $report['late_review_count'] > 0) {
                throw ValidationException::withMessages(['disbursement' => 'Resolve pending payments, refunds, and late-review receipts before requesting payout.']);
            }
            if ((int) $report['net_support_minor'] < 1) {
                throw ValidationException::withMessages(['disbursement' => 'There is no confirmed net support available to disburse.']);
            }
            $beneficiary = PayoutBeneficiaryModel::query()->find((string) $collection->beneficiary_id);
            if ($beneficiary === null || $beneficiary->status !== PayoutBeneficiaryStatus::Verified
                || $beneficiary->organisation_id !== $community->organisation_id
                || ! in_array($beneficiary->account_type, ['bank_account', 'mobile_money', 'wallet'], true)
                || $beneficiary->currency_code !== $collection->currency_code) {
                throw ValidationException::withMessages(['beneficiary_id' => 'The collection destination must be verified for its organisation and currency.']);
            }
            $payout = $this->db()->table('community_services.funeral_disbursements')->where('collection_id', $collectionId)->lockForUpdate()->first();
            if ($payout !== null && in_array($payout->status, ['awaiting_approval', 'processing', 'paid'], true)) {
                throw ValidationException::withMessages(['disbursement' => 'A disbursement for this collection is already open or complete.']);
            }
            $id = $payout?->id ?? (string) Uuid::uuid7();
            $now = now();
            $values = [
                'beneficiary_id' => $beneficiary->id,
                'destination_type' => $beneficiary->account_type,
                'amount_minor' => (int) $report['net_support_minor'],
                'currency_code' => $collection->currency_code,
                'status' => 'awaiting_approval', 'requested_by' => $actorId, 'requested_at' => $now,
                'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejection_reason' => null,
                'provider_reference' => null, 'receipt_reference' => null, 'ledger_journal_id' => null,
                'paid_at' => null, 'updated_at' => $now,
            ];
            if ($payout === null) {
                $this->db()->table('community_services.funeral_disbursements')->insert(array_merge([
                    'id' => $id, 'collection_id' => $collectionId, 'created_at' => $now,
                ], $values));
            } else {
                $this->db()->table('community_services.funeral_disbursements')->where('id', $id)->update($values);
            }
            $this->audit('disbursement.requested', $actorId, 'disbursement', $id, $payout->status ?? null, 'awaiting_approval');
            return (array) $this->db()->table('community_services.funeral_disbursements')->where('id', $id)->first();
        });
    }

    /** @return array<string, mixed> */
    public function approveDisbursement(string $collectionId, string $reviewerId): array
    {
        $this->assertLocalPayoutEnabled();
        $payout = $this->db()->table('community_services.funeral_disbursements as payout')
            ->join('community_services.funeral_collections as collection', 'collection.id', '=', 'payout.collection_id')
            ->where('payout.collection_id', $collectionId)->first(['payout.*', 'collection.community_id']);
        abort_if($payout === null, 404);
        $community = $this->community((string) $payout->community_id);
        $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
        if ($payout->requested_by === $reviewerId) {
            throw ValidationException::withMessages(['disbursement' => 'A different reviewer must approve a pending disbursement.']);
        }
        if ($payout->status === 'awaiting_approval') {
            $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->update([
                'status' => 'processing', 'approved_by' => $reviewerId, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('disbursement.approved', $reviewerId, 'disbursement', (string) $payout->id, 'awaiting_approval', 'processing');
            $payout->approved_by = $reviewerId;
            $payout->status = 'processing';
        } elseif ($payout->status !== 'processing' || $payout->approved_by !== $reviewerId) {
            throw ValidationException::withMessages(['disbursement' => 'Only the recorded independent approver can retry a processing payout.']);
        }

        try {
            $collection = $this->collection($collectionId);
            $beneficiary = PayoutBeneficiaryModel::query()->findOrFail((string) $payout->beneficiary_id);
            $journalId = $this->postDisbursementJournal($collection, $payout, $beneficiary);
            $receipt = 'FUNERAL-PAYOUT-'.strtoupper(substr(str_replace('-', '', (string) $payout->id), 0, 16));
            $providerReference = $payout->destination_type === 'wallet' ? null : 'MOCK-BANK-'.strtoupper(substr(str_replace('-', '', (string) Uuid::uuid7()), 0, 16));
            $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->update([
                'status' => 'paid', 'ledger_journal_id' => $journalId, 'provider_reference' => $providerReference,
                'receipt_reference' => $receipt, 'paid_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('disbursement.paid', $reviewerId, 'disbursement', (string) $payout->id, 'processing', 'paid');
            return (array) $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->first();
        } catch (\Throwable $exception) {
            $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->update([
                'status' => 'failed', 'updated_at' => now(),
            ]);
            $this->audit('disbursement.failed', $reviewerId, 'disbursement', (string) $payout->id, 'processing', 'failed');
            throw $exception;
        }
    }

    public function rejectDisbursement(string $collectionId, string $reason, string $reviewerId): array
    {
        $payout = $this->db()->table('community_services.funeral_disbursements as payout')
            ->join('community_services.funeral_collections as collection', 'collection.id', '=', 'payout.collection_id')
            ->where('payout.collection_id', $collectionId)->first(['payout.*', 'collection.community_id']);
        abort_if($payout === null, 404);
        $community = $this->community((string) $payout->community_id);
        $this->assertCommunityReviewer((string) $community->id, $reviewerId, (string) $community->created_by);
        if ($payout->requested_by === $reviewerId || $payout->status !== 'awaiting_approval') {
            throw ValidationException::withMessages(['disbursement' => 'A different reviewer can reject only a pending disbursement.']);
        }
        $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->update([
            'status' => 'rejected', 'rejected_by' => $reviewerId, 'rejection_reason' => trim($reason), 'updated_at' => now(),
        ]);
        $this->audit('disbursement.rejected', $reviewerId, 'disbursement', (string) $payout->id, 'awaiting_approval', 'rejected');
        return (array) $this->db()->table('community_services.funeral_disbursements')->where('id', $payout->id)->first();
    }

    private function assertLocalPayoutEnabled(): void
    {
        if (! app()->environment('local', 'development', 'testing') || config('payment.mock_bank.local_funeral_ledger_enabled') !== true) {
            abort(503, 'Funeral payouts require the local mock-bank ledger configuration. No external bank payout adapter is configured.');
        }
    }

    private function postDisbursementJournal(object $collection, object $payout, PayoutBeneficiaryModel $beneficiary): string
    {
        $suspenseRef = Uuid::uuid5(Uuid::NAMESPACE_URL,
            'olekard.local/mock-bank/funeral-contribution-suspense/'.$this->community((string) $collection->community_id)->organisation_id.'/'.$collection->currency_code)->toString();
        $amount = (int) $payout->amount_minor;
        if ($amount < 1 || $this->ledger->getBalance($suspenseRef) < $amount) {
            throw ValidationException::withMessages(['disbursement' => 'The funeral contribution suspense ledger does not have enough posted funds.']);
        }
        $lines = [['ledger_account_reference' => $suspenseRef, 'direction' => 'debit', 'amount' => $amount]];
        if ($beneficiary->account_type === 'wallet') {
            $walletId = (string) $beneficiary->account_number;
            if (! Uuid::isValid($walletId) || ! $this->wallet->isActive($walletId)
                || ! $this->wallet->pocketExists($walletId, (string) $payout->currency_code)) {
                throw ValidationException::withMessages(['beneficiary_id' => 'The verified wallet beneficiary is not active or has no matching currency pocket.']);
            }
            $walletRef = $this->wallet->getPocketLedgerAccountRef($walletId, (string) $payout->currency_code);
            if ($walletRef === null) throw ValidationException::withMessages(['beneficiary_id' => 'The wallet payout pocket could not be resolved.']);
            $lines[] = ['ledger_account_reference' => $walletRef, 'direction' => 'credit', 'amount' => $amount];
        } else {
            $sources = $this->mockBankSourceAccounts((string) $payout->collection_id, (string) $payout->currency_code, $amount);
            foreach ($sources as $source) {
                $lines[] = ['ledger_account_reference' => $source['reference'], 'direction' => 'credit', 'amount' => $source['amount']];
            }
        }
        $key = 'funeral:disbursement:'.$payout->id;
        $journalId = $this->ledger->postEntry([
            'idempotency_key' => $key, 'entry_type' => 'debit', 'currency_code' => (string) $payout->currency_code,
            'transaction_reference' => (string) $payout->id, 'correlation_id' => (string) $payout->id,
            'actor_id' => (string) $payout->approved_by, 'description' => 'Local Funeral Assistance beneficiary disbursement '.$payout->destination_type,
            'lines' => $lines,
        ]);
        $evidence = $this->ledger->findPostedJournalEvidence($key);
        if ($evidence === null || $evidence->journalId !== $journalId || $evidence->status !== 'posted'
            || $evidence->currencyCode !== $payout->currency_code) {
            throw new \RuntimeException('Ledger did not return matching posted funeral disbursement evidence.');
        }
        return $journalId;
    }

    /** @return list<array{reference:string, amount:int}> */
    private function mockBankSourceAccounts(string $collectionId, string $currencyCode, int $amount): array
    {
        $obligationIds = $this->db()->table('community_services.funeral_obligations')->where('collection_id', $collectionId)->pluck('id')->all();
        $providerAccountIds = $obligationIds === [] ? collect() : DB::connection('payment')->table('payment.payment_intents as pi')
            ->join('payment.payment_attempts as pa', 'pa.payment_intent_id', '=', 'pi.id')
            ->join('payment.payment_provider_accounts as ppa', 'ppa.id', '=', 'pa.provider_account_id')
            ->join('payment.payment_providers as pp', 'pp.id', '=', 'ppa.provider_id')
            ->join('community_services.funeral_payment_receipts as receipt', 'receipt.payment_intent_id', '=', 'pi.id')
            ->join('community_services.funeral_obligations as obligation', 'obligation.id', '=', 'receipt.obligation_id')
            ->whereIn('obligation.id', $obligationIds)->where('receipt.domain_status', 'confirmed')
            ->where('pp.code', 'mock_bank')->where('pa.status', 'succeeded')->distinct()->pluck('pa.provider_account_id');
        $left = $amount;
        $sources = [];
        foreach ($providerAccountIds as $providerAccountId) {
            $reference = Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/mock-bank/provider-clearing/'.$providerAccountId.'/'.$currencyCode)->toString();
            $balance = max(0, $this->ledger->getBalance($reference));
            $allocated = min($left, $balance);
            if ($allocated > 0) $sources[] = ['reference' => $reference, 'amount' => $allocated];
            $left -= $allocated;
            if ($left === 0) break;
        }
        if ($left > 0) throw ValidationException::withMessages(['disbursement' => 'Available mock-bank clearing funds do not cover this payout.']);
        return $sources;
    }

    /** @return list<array<string, mixed>> */
    public function transactionHistory(string $collectionId, string $actorId): array
    {
        $collection = $this->collection($collectionId);
        $community = $this->community((string) $collection->community_id);
        $this->assertCommunityManager((string) $community->id, $actorId, (string) $community->created_by);
        $obligations = $this->db()->table('community_services.funeral_obligations as o')
            ->leftJoin('community_services.funeral_payment_receipts as r', 'r.obligation_id', '=', 'o.id')
            ->leftJoin('community_services.funeral_late_reviews as lr', 'lr.payment_intent_id', '=', 'r.payment_intent_id')
            ->where('o.collection_id', $collectionId)
            ->get(['o.id as obligation_id', 'o.household_id', 'r.payment_intent_id', 'r.gross_amount_minor', 'r.fee_amount_minor', 'r.currency_code', 'r.domain_status', 'r.confirmed_at', 'r.correlation_id', 'lr.reason as review_reason']);
        $receipts = $obligations->filter(static fn (object $row): bool => $row->payment_intent_id !== null)
            ->map(static fn (object $row): array => [
                'obligation_id' => $row->obligation_id, 'payment_intent_id' => $row->payment_intent_id,
                'reference' => $row->correlation_id ?: $row->payment_intent_id,
                'amount_minor' => (int) $row->gross_amount_minor, 'fee_minor' => (int) $row->fee_amount_minor,
                'currency_code' => $row->currency_code, 'status' => $row->domain_status === 'confirmed' ? 'succeeded' : 'late_review',
                'review_reason' => $row->review_reason, 'occurred_at' => $row->confirmed_at,
            ])->values()->all();
        $receiptIntentIds = array_column($receipts, 'payment_intent_id');
        if ($receiptIntentIds !== []) {
            $intentStatus = DB::connection('payment')->table('payment.payment_intents')->whereIn('id', $receiptIntentIds)->pluck('status', 'id');
            $attemptIds = DB::connection('payment')->table('payment.payment_attempts')->whereIn('payment_intent_id', $receiptIntentIds)->pluck('id', 'payment_intent_id');
            $reversedIntentIds = $attemptIds->isEmpty() ? [] : DB::connection('payment')->table('payment.payment_dispute_cases')
                ->whereIn('payment_attempt_id', $attemptIds->values())->where('case_type', 'reversal')->where('status', 'closed_reversed')
                ->get(['payment_attempt_id'])->map(static fn (object $case): string => (string) $attemptIds->search($case->payment_attempt_id))->filter()->values()->all();
            $refundRows = DB::connection('payment')->table('payment.payment_refunds')->whereIn('payment_intent_id', $receiptIntentIds)
                ->get(['payment_intent_id', 'amount_minor', 'status'])->groupBy('payment_intent_id');
            foreach ($receipts as &$receipt) {
                if ($receipt['status'] !== 'late_review') {
                    $refundsForIntent = $refundRows->get($receipt['payment_intent_id'], collect());
                    $reversed = in_array($receipt['payment_intent_id'], $reversedIntentIds, true)
                        || $refundsForIntent->contains(static fn (object $refund): bool => $refund->status === 'reversed');
                    $refundedMinor = (int) $refundsForIntent->whereIn('status', ['confirmed', 'succeeded', 'settled'])->sum('amount_minor');
                    $hasPendingRefund = $refundsForIntent->contains(static fn (object $refund): bool => in_array($refund->status, ['requested', 'pending_approval', 'processing', 'submitted', 'pending', 'unknown'], true));
                    $receipt['status'] = $reversed ? 'reversed'
                        : ((string) ($intentStatus[$receipt['payment_intent_id']] ?? '') === 'refunded' || ($refundedMinor >= (int) $receipt['amount_minor'] && $refundedMinor > 0) ? 'refunded'
                            : ((string) ($intentStatus[$receipt['payment_intent_id']] ?? '') === 'partially_refunded' || $refundedMinor > 0 ? 'partially_refunded'
                                : ($hasPendingRefund ? 'refund_pending' : (string) ($intentStatus[$receipt['payment_intent_id']] ?? $receipt['status']))));
                }
            }
            unset($receipt);
        }
        $known = array_column($receipts, 'payment_intent_id');
        $intentRows = $this->db()->table('community_services.funeral_obligations as o')
            ->join('payment.payment_intents as pi', 'pi.purpose_reference', '=', 'o.id')
            ->where('o.collection_id', $collectionId)->where('pi.purpose_type', 'funeral_contribution')
            ->whereNotIn('pi.id', $known === [] ? ['00000000-0000-0000-0000-000000000000'] : $known)
            ->orderByDesc('pi.created_at')->get(['o.id as obligation_id', 'pi.id as payment_intent_id', 'pi.amount_minor', 'pi.currency_code', 'pi.status', 'pi.created_at']);
        foreach ($intentRows as $row) {
            $receipts[] = ['obligation_id' => $row->obligation_id, 'payment_intent_id' => $row->payment_intent_id,
                'reference' => $row->payment_intent_id, 'amount_minor' => (int) $row->amount_minor, 'fee_minor' => 0,
                'currency_code' => $row->currency_code, 'status' => (string) $row->status, 'review_reason' => null,
                'occurred_at' => $row->created_at];
        }
        usort($receipts, static fn (array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
        return $receipts;
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
