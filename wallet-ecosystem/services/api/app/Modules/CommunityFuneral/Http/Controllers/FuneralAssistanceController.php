<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Controllers;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Http\Requests\AddRepresentativeRequest;
use App\Modules\CommunityFuneral\Http\Requests\AddStaffRequest;
use App\Modules\CommunityFuneral\Http\Requests\CarryForwardRequest;
use App\Modules\CommunityFuneral\Http\Requests\ChangeBeneficiaryRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateCollectionRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateCommunityRequest;
use App\Modules\CommunityFuneral\Http\Requests\CreateHouseholdRequest;
use App\Modules\CommunityFuneral\Http\Requests\ReviewHouseholdRequest;
use App\Modules\CommunityFuneral\Http\Resources\FuneralCollectionResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralCommunityResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralObligationResource;
use App\Modules\CommunityFuneral\Http\Resources\FuneralReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FuneralAssistanceController
{
    public function __construct(private readonly FuneralAssistanceService $service) {}

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
        return response()->json(['success' => true, 'data' => $this->service->createHousehold($communityId, $data['private_reference'], $this->actor($request))], 201);
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
        $collection = $this->service->createCollection($communityId, $data['beneficiary_id'], $data['evidence_reference'] ?? null, $this->actor($request));
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

    public function payoutBlocked(): JsonResponse
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'PAYOUT_PREREQUISITES_UNRESOLVED',
            'message' => 'Family disbursement is unavailable until the shared P18 payout contract and finance approvals are released.',
        ]], 503);
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('identity.user_id', '');
    }
}
