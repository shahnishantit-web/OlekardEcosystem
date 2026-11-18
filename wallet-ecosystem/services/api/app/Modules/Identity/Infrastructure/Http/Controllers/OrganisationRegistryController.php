<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\ReviewedOrganisationMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

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

    public function updateOrganisation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'registry_reference' => ['required', 'string', 'max:120'],
            'display_name' => ['required', 'string', 'max:200'],
        ]);
        return response()->json(['data' => $this->registry->updateOrganisation(
            $id, $data['registry_reference'], $data['display_name'], $this->actor($request),
        )]);
    }

    public function deleteOrganisation(Request $request, string $id): JsonResponse
    {
        $this->registry->deleteOrganisation($id, $this->actor($request));
        return response()->json(['data' => ['id' => $id, 'status' => 'deleted']]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $stage = $request->query('stage', 'review');
        abort_unless(in_array($stage, ['mine', 'review', 'approval', 'active', 'all'], true), 422, 'Invalid organisation queue stage.');
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
            'user_id' => ['nullable', 'uuid', 'required_without:user_email'],
            'user_email' => ['nullable', 'email', 'max:255', 'required_without:user_id'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);
        $userId = $data['user_id'] ?? \Illuminate\Support\Facades\DB::connection('identity')
            ->table('identity.users')->where('email', $data['user_email'])->where('status', 'active')->value('id');
        if ($userId === null) throw ValidationException::withMessages(['user_email' => 'No active user was found for this email address.']);
        return response()->json(['data' => $this->registry->requestMembership(
            $id, (string) $userId, $data['evidence_reference'], $this->actor($request),
        )], 201);
    }

    public function indexMemberships(Request $request): JsonResponse
    {
        $stage = $request->query('stage', 'review');
        abort_unless(in_array($stage, ['mine', 'review', 'directory'], true), 422, 'Invalid membership queue stage.');
        $data = $request->validate(['organisation_id' => ['required_if:stage,directory', 'nullable', 'uuid']]);
        return response()->json(['data' => $this->registry->membershipQueue($this->actor($request), $stage, $data['organisation_id'] ?? null)]);
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
