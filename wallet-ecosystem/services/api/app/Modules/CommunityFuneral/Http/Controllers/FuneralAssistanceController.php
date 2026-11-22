<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Controllers;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Application\FuneralFeatureReadiness;
use App\Modules\CommunityFuneral\Http\Requests\AddRepresentativeRequest;
use App\Modules\CommunityFuneral\Http\Requests\AddStaffRequest;
use App\Modules\CommunityFuneral\Http\Requests\CarryForwardRequest;
use App\Modules\CommunityFuneral\Http\Requests\ChangeBeneficiaryRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateCollectionRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateCommunityRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateHouseholdRequest;
use App\Modules\CommunityFuneral\Http\Requests\ClaimHouseholdInvitationRequest;
use App\Modules\CommunityFuneral\Http\Requests\ReviewHouseholdRequest;
use App\Modules\CommunityFuneral\Http\Resources\FuneralCollectionResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralCommunityResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralObligationResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FuneralAssistanceController
{
    public function __construct(
        private readonly FuneralAssistanceService $service,
        private readonly FuneralFeatureReadiness $readiness,
    ) {}

    public function readiness(): JsonResponse
    {
        $enabled = $this->readiness->isEnabled();

        return response()->json(['success' => true, 'data' => [
            'enabled' => $enabled,
            'code' => $enabled ? null : 'FUNERAL_ASSISTANCE_PREREQUISITES_UNRESOLVED',
            'message' => $enabled ? null : 'Funeral Assistance is unavailable pending tenant scope and shared payout readiness.',
        ]]);
    }

    public function organisations(Request $request): JsonResponse
    {
        $actorId = $this->actor($request);
        $rows = DB::connection('identity')->table('identity.organisation_memberships as membership')
            ->join('identity.organisations as organisation', 'organisation.id', '=', 'membership.organisation_id')
            ->where('membership.user_id', $actorId)->where('membership.status', 'active')
            ->where('organisation.status', 'active')
            ->orderBy('organisation.display_name')
            ->get(['organisation.id', 'organisation.display_name as name']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function communities(Request $request): JsonResponse
    {
        $actorId = $this->actor($request);
        $rows = DB::connection('community_services')->table('community_services.funeral_communities as community')
            ->leftJoin('community_services.funeral_community_staff as staff', function ($join) use ($actorId): void {
                $join->on('staff.community_id', '=', 'community.id')->where('staff.actor_id', '=', $actorId)->where('staff.status', '=', 'active');
            })
            ->where(function ($query) use ($actorId): void {
                $query->where('community.created_by', $actorId)->orWhereNotNull('staff.id');
            })
            ->orderByDesc('community.created_at')
            ->get([
                'community.id', 'community.organisation_id', 'community.display_name', 'community.status',
                'community.policy_version', 'community.created_by', 'community.created_at',
            ]);
        $organisationNames = DB::connection('identity')->table('identity.organisations')
            ->whereIn('id', $rows->pluck('organisation_id'))->pluck('display_name', 'id');
        $rows = $rows->map(fn (object $row): array => array_merge((array) $row, [
            'organisation_name' => (string) ($organisationNames[$row->organisation_id] ?? 'Organisation'),
        ]));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function workspace(Request $request, string $communityId): JsonResponse
    {
        $community = $this->accessibleCommunity($communityId, $this->actor($request));
        $db = DB::connection('community_services');
        $staff = $db->table('community_services.funeral_community_staff as staff')
            ->where('staff.community_id', $communityId)->where('staff.status', 'active')
            ->orderBy('staff.role')->get([
                'staff.id', 'staff.actor_id', 'staff.role', 'staff.status',
            ]);
        $households = $db->table('community_services.funeral_households as household')
            ->where('household.community_id', $communityId)->orderByDesc('household.created_at')
            ->get([
                'household.id', 'household.private_reference', 'household.status', 'household.exemption_reference',
                'household.reviewed_by', 'household.reviewed_at', 'household.created_by', 'household.created_at',
                'household.contact_phone_encrypted', 'household.contact_consent_at',
            ]);
        $representatives = $db->table('community_services.funeral_representatives as representative')
            ->join('community_services.funeral_households as household', 'household.id', '=', 'representative.household_id')
            ->where('household.community_id', $communityId)
            ->get([
                'representative.id', 'representative.household_id', 'representative.actor_id', 'representative.status',
                'representative.evidence_reference', 'representative.verified_by', 'representative.verified_at',
                'representative.verification_method',
            ])->groupBy('household_id');
        $collections = $db->table('community_services.funeral_collections as collection')
            ->leftJoin('community_services.funeral_closures as closure', 'closure.collection_id', '=', 'collection.id')
            ->where('collection.community_id', $communityId)->orderByDesc('collection.created_at')
            ->get([
                'collection.id', 'collection.status', 'collection.beneficiary_id',
                'collection.target_household_id',
                'collection.purpose', 'collection.evidence_reference', 'collection.amount_minor', 'collection.currency_code', 'collection.terms_version',
                'collection.created_by', 'collection.reviewed_by', 'collection.reviewed_at', 'collection.opened_at', 'collection.closed_at',
                'closure.net_support_minor', 'closure.unresolved_liability_count', 'closure.carry_forward_reference',
                'closure.carry_forward_requested_by', 'closure.carry_forward_approved_by', 'closure.payout_status', 'closure.settlement_status',
            ]);
        $payouts = $db->table('community_services.funeral_disbursements')->whereIn('collection_id', $collections->pluck('id'))
            ->get(['id', 'collection_id', 'destination_type', 'amount_minor', 'currency_code', 'status', 'requested_by', 'requested_at', 'approved_by', 'approved_at', 'rejected_by', 'rejection_reason', 'provider_reference', 'receipt_reference', 'ledger_journal_id', 'paid_at'])->keyBy('collection_id');
        $identityIds = $staff->pluck('actor_id')
            ->merge($households->pluck('created_by'))->merge($households->pluck('reviewed_by'))
            ->merge($representatives->flatten(1)->pluck('actor_id'))->merge($representatives->flatten(1)->pluck('verified_by'))
            ->filter()->unique()->values();
        $identityUsers = DB::connection('identity')->table('identity.users')->whereIn('id', $identityIds)
            ->get(['id', 'email', 'mobile_number'])->keyBy('id');
        $beneficiaries = DB::connection('payment')->table('payment.payout_beneficiaries')
            ->whereIn('id', $collections->pluck('beneficiary_id'))
            ->get(['id', 'account_holder_name'])->keyBy('id');
        $obligationCounts = $db->table('community_services.funeral_obligations')
            ->whereIn('collection_id', $collections->pluck('id'))
            ->selectRaw('collection_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE status = ?) AS unpaid, COUNT(*) FILTER (WHERE fulfilment_status = ?) AS fulfilled', ['unpaid', 'fulfilled'])
            ->groupBy('collection_id')->get()->keyBy('collection_id');
        $householdById = $households->keyBy('id');
        $collectionRows = $collections->map(function (object $row) use ($obligationCounts, $beneficiaries, $householdById, $payouts): array {
            $counts = $obligationCounts->get($row->id);
            return array_merge((array) $row, [
                'beneficiary_name' => (string) ($beneficiaries->get($row->beneficiary_id)->account_holder_name ?? 'Verified beneficiary'),
                'target_household_reference' => $householdById->get($row->target_household_id)->private_reference ?? null,
                'obligation_count' => (int) ($counts->total ?? 0),
                'unpaid_count' => (int) ($counts->unpaid ?? 0),
                'fulfilled_count' => (int) ($counts->fulfilled ?? 0),
                'disbursement' => $payouts->get($row->id) === null ? null : (array) $payouts->get($row->id),
            ]);
        });
        $staff = $staff->map(fn (object $row): array => array_merge((array) $row, (array) ($identityUsers->get($row->actor_id) ?? [])));
        $invitations = $db->table('community_services.funeral_household_invitations')
            ->whereIn('household_id', $households->pluck('id'))->orderByDesc('created_at')
            ->get(['id', 'household_id', 'collection_id', 'status', 'sent_at', 'expires_at'])
            ->groupBy('household_id');
        $householdRows = $households->map(function (object $row) use ($identityUsers, $representatives, $invitations): array {
            $household = (array) $row;
            unset($household['contact_phone_encrypted']);
            return array_merge($household, [
            'creator_email' => $identityUsers->get($row->created_by)->email ?? null,
            'reviewer_email' => $identityUsers->get($row->reviewed_by)->email ?? null,
            'has_consented_contact' => $row->contact_consent_at !== null && $row->contact_phone_encrypted !== null,
            'invitation_status' => $invitations->get($row->id)?->first()?->status,
            'invitation_sent_at' => $invitations->get($row->id)?->first()?->sent_at,
            'representatives' => ($representatives->get($row->id) ?? collect())->map(fn (object $rep): array => array_merge((array) $rep, [
                'email' => $identityUsers->get($rep->actor_id)->email ?? null,
                'mobile_number' => $identityUsers->get($rep->actor_id)->mobile_number ?? null,
            ]))->values(),
        ]);
        });

        return response()->json(['success' => true, 'data' => [
            'community' => [
                'id' => (string) $community->id, 'organisation_id' => (string) $community->organisation_id,
                'organisation_name' => (string) $community->organisation_name, 'display_name' => (string) $community->display_name,
                'status' => (string) $community->status, 'policy_version' => (string) $community->policy_version,
                'created_by' => (string) $community->created_by,
            ],
            'staff' => $staff,
            'households' => $householdRows,
            'collections' => $collectionRows,
        ]]);
    }

    public function directory(Request $request, string $communityId): JsonResponse
    {
        $community = $this->accessibleCommunity($communityId, $this->actor($request));
        $members = DB::connection('identity')->table('identity.organisation_memberships as membership')
            ->join('identity.users as user', 'user.id', '=', 'membership.user_id')
            ->where('membership.organisation_id', $community->organisation_id)
            ->where('membership.status', 'active')->where('user.status', 'active')
            ->orderBy('user.email')->get([
                'user.id as actor_id', 'user.email', 'user.mobile_number', 'user.actor_type',
            ]);
        $beneficiaries = DB::connection('payment')->table('payment.payout_beneficiaries')
            ->where('organisation_id', $community->organisation_id)->where('status', 'verified')
            ->orderBy('account_holder_name')->get([
                'id', 'account_holder_name', 'account_type', 'bank_name', 'currency_code',
            ]);

        return response()->json(['success' => true, 'data' => [
            'members' => $members,
            'beneficiaries' => $beneficiaries,
        ]]);
    }

    public function createCommunity(CreateCommunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $community = $this->service->createCommunity($data['organisation_id'], $data['display_name'], $this->actor($request));
        return response()->json(['success' => true, 'data' => (new FuneralCommunityResource($community))->resolve()], 201);
    }

    public function addStaff(AddStaffRequest $request, string $communityId): JsonResponse
    {
        $data = $request->validated();
        $this->service->addCommunityStaff($communityId, $data['actor_id'], $data['role'], $this->actor($request));
        return response()->json(['success' => true, 'data' => ['community_id' => $communityId, 'actor_id' => $data['actor_id'], 'role' => $data['role']]]);
    }

    public function createHousehold(CreateHouseholdRequest $request, string $communityId): JsonResponse
    {
        $data = $request->validated();
        return response()->json(['success' => true, 'data' => $this->service->createHousehold(
            $communityId,
            $data['private_reference'],
            $this->actor($request),
            $data['contact_phone'] ?? null,
            (bool) ($data['contact_consent'] ?? false),
        )], 201);
    }

    public function reviewHousehold(ReviewHouseholdRequest $request, string $householdId): JsonResponse
    {
        $data = $request->validated();
        $this->service->reviewHousehold($householdId, $data['decision'], $data['exemption_reference'] ?? null, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['household_id' => $householdId, 'status' => $data['decision']]]);
    }

    public function addRepresentative(AddRepresentativeRequest $request, string $householdId): JsonResponse
    {
        $data = $request->validated();
        return response()->json(['success' => true, 'data' => $this->service->addRepresentative($householdId, $data['actor_id'], $data['evidence_reference'] ?? null, $this->actor($request))], 201);
    }

    public function verifyRepresentative(Request $request, string $householdId, string $representativeId): JsonResponse
    {
        $this->service->verifyRepresentative($householdId, $representativeId, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['representative_id' => $representativeId, 'status' => 'verified']]);
    }

    public function revokeRepresentative(Request $request, string $householdId, string $representativeId): JsonResponse
    {
        $this->service->revokeRepresentative($householdId, $representativeId, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['representative_id' => $representativeId, 'status' => 'revoked']]);
    }

    public function createCollection(CreateCollectionRequest $request, string $communityId): JsonResponse
    {
        $data = $request->validated();
        $collection = $this->service->createCollection(
            $communityId,
            $data['beneficiary_id'],
            $data['target_household_id'],
            $data['evidence_reference'] ?? null,
            $this->actor($request),
            $data['purpose'] ?? null,
        );
        $collection['target_household_reference'] = DB::connection('community_services')->table('community_services.funeral_households')
            ->where('id', $data['target_household_id'])->value('private_reference');
        return response()->json(['success' => true, 'data' => (new FuneralCollectionResource($collection))->resolve()], 201);
    }

    public function changeBeneficiary(ChangeBeneficiaryRequest $request, string $collectionId): JsonResponse
    {
        $data = $request->validated();
        $this->service->changeBeneficiary($collectionId, $data['beneficiary_id'], $this->actor($request));
        return response()->json(['success' => true, 'data' => ['collection_id' => $collectionId, 'status' => 'under_review']]);
    }

    public function approve(Request $request, string $collectionId): JsonResponse
    {
        $this->service->approveCollection($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['collection_id' => $collectionId, 'status' => 'approved']]);
    }

    public function activate(Request $request, string $collectionId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->activateCollection($collectionId, $this->actor($request))]);
    }

    public function myObligations(Request $request): JsonResponse
    {
        $obligations = $this->service->obligationsForActor($this->actor($request));
        return response()->json(['success' => true, 'data' => FuneralObligationResource::collection($obligations)->resolve()]);
    }

    public function claimHouseholdInvitation(ClaimHouseholdInvitationRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->claimHouseholdInvitation(
            $request->validated('token'),
            $this->actor($request),
        )]);
    }

    public function householdInvitationLink(Request $request, string $householdId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->householdInvitationLink(
            $householdId,
            $this->actor($request),
        )]);
    }

    public function close(Request $request, string $collectionId): JsonResponse
    {
        $this->service->closeCollection($collectionId, $this->actor($request));
        $report = $this->service->aggregateReport($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => (new FuneralReportResource($report))->resolve()]);
    }

    public function report(Request $request, string $collectionId): JsonResponse
    {
        $report = $this->service->aggregateReport($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => (new FuneralReportResource($report))->resolve()]);
    }

    public function transactions(Request $request, string $collectionId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->transactionHistory($collectionId, $this->actor($request))]);
    }

    public function exportTransactions(Request $request, string $collectionId): \Symfony\Component\HttpFoundation\Response
    {
        $rows = $this->service->transactionHistory($collectionId, $this->actor($request));
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Date', 'Channel', 'Reference', 'Payment Intent', 'Obligation', 'Amount Minor', 'Currency', 'Fee Minor', 'Status', 'Review Reason']);
        foreach ($rows as $row) {
            fputcsv($stream, [$row['occurred_at'], $row['channel'] ?? 'in_app', $row['reference'], $row['payment_intent_id'], $row['obligation_id'], $row['amount_minor'], $row['currency_code'], $row['fee_minor'], $row['status'], $row['review_reason']]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="funeral-collection-'.$collectionId.'-transactions.csv"',
        ]);
    }

    public function archive(Request $request, string $collectionId): JsonResponse
    {
        $this->service->archiveCollection($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['collection_id' => $collectionId, 'status' => 'archived']]);
    }

    public function requestCarryForward(CarryForwardRequest $request, string $collectionId): JsonResponse
    {
        $data = $request->validated();
        $this->service->requestCarryForward($collectionId, $data['carry_forward_reference'], $this->actor($request));
        return response()->json(['success' => true, 'data' => ['collection_id' => $collectionId, 'status' => 'awaiting_approval']]);
    }

    public function approveCarryForward(Request $request, string $collectionId): JsonResponse
    {
        $this->service->approveCarryForward($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => ['collection_id' => $collectionId, 'status' => 'approved']]);
    }

    public function requestDisbursement(Request $request, string $collectionId): JsonResponse
    {
        $payout = $this->service->requestDisbursement($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => $payout], 201);
    }

    public function approveDisbursement(Request $request, string $collectionId): JsonResponse
    {
        $payout = $this->service->approveDisbursement($collectionId, $this->actor($request));
        return response()->json(['success' => true, 'data' => $payout]);
    }

    public function rejectDisbursement(Request $request, string $collectionId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $payout = $this->service->rejectDisbursement($collectionId, $data['reason'], $this->actor($request));
        return response()->json(['success' => true, 'data' => $payout]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('identity.user_id', '');
    }

    private function accessibleCommunity(string $communityId, string $actorId): object
    {
        $community = DB::connection('community_services')->table('community_services.funeral_communities as community')
            ->where('community.id', $communityId)
            ->first([
                'community.id', 'community.organisation_id', 'community.display_name', 'community.status',
                'community.policy_version', 'community.created_by',
            ]);
        abort_if($community === null, 404);
        $isOwner = $community->created_by === $actorId;
        $isStaff = DB::connection('community_services')->table('community_services.funeral_community_staff')
            ->where('community_id', $communityId)->where('actor_id', $actorId)->where('status', 'active')->exists();
        abort_unless($isOwner || $isStaff, 403, 'Community access is not authorised.');

        $community->organisation_name = (string) (DB::connection('identity')->table('identity.organisations')
            ->where('id', $community->organisation_id)->value('display_name') ?? 'Organisation');

        return $community;
    }
}
