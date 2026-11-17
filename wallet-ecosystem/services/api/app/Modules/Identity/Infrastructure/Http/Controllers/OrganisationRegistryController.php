<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\ReviewedOrganisationMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class OrganisationRegistryController extends Controller
{
    public function __construct(private readonly ReviewedOrganisationMembershipService $registry) {}

    public function requestOrganisation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registry_reference' => ['required', 'string', 'max:120'],
            'display_name' => ['required', 'string', 'max:200'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);
        return response()->json(['data' => $this->registry->requestOrganisation(
            $data['registry_reference'], $data['display_name'], $data['evidence_reference'], $this->actor($request),
        )], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $stage = $request->query('stage', 'review');
        abort_unless(in_array($stage, ['mine', 'review', 'approval'], true), 422, 'Invalid organisation queue stage.');
        return response()->json(['data' => $this->registry->organisationQueue($actor, $stage)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->registry->organisationDetails($id, $this->actor($request))]);
    }

    public function reviewOrganisation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:pending_approval,rejected'], 'comments' => ['nullable', 'string', 'max:2000']]);
        $this->registry->reviewOrganisation($id, $data['decision'], $this->actor($request), $data['comments'] ?? null);
        return response()->json(['data' => ['id' => $id, 'status' => $data['decision']]]);
    }

    public function approveOrganisation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:active,rejected'], 'comments' => ['nullable', 'string', 'max:2000']]);
        $this->registry->approveOrganisation($id, $data['decision'], $data['comments'] ?? null, $this->actor($request));
        return response()->json(['data' => ['id' => $id, 'status' => $data['decision']]]);
    }

    public function suspendOrganisation(Request $request, string $id): JsonResponse
    {
        $this->registry->suspendOrganisation($id, $this->actor($request));
        return response()->json(['data' => ['id' => $id, 'status' => 'suspended']]);
    }

    public function requestMembership(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);
        return response()->json(['data' => $this->registry->requestMembership(
            $id, $data['user_id'], $data['evidence_reference'], $this->actor($request),
        )], 201);
    }

    public function reviewMembership(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:active,rejected']]);
        $this->registry->reviewMembership($id, $data['decision'], $this->actor($request));
        return response()->json(['data' => ['id' => $id, 'status' => $data['decision']]]);
    }

    public function revokeMembership(Request $request, string $id): JsonResponse
    {
        $this->registry->revokeMembership($id, $this->actor($request));
        return response()->json(['data' => ['id' => $id, 'status' => 'revoked']]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('identity.user_id', '');
    }
}
