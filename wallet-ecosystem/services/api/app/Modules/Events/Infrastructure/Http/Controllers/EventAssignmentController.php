<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventAssignmentManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventAssignmentController extends Controller
{
    public function __construct(private readonly EventAssignmentManagementService $assignments) {}

    public function storeOrganisation(Request $request, string $organisationId): JsonResponse
    {
        $data = $request->validate([
            'target_actor_id' => ['required', 'uuid'],
            'permission_slug' => ['required', 'string', 'max:100'],
            'reason_code' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $this->assignments->grantOrganisationAssignment(
            $organisationId, $data['target_actor_id'], $data['permission_slug'], $this->actor($request),
            $data['reason_code'], $this->correlationId($request),
        )], 201);
    }

    public function storeEvent(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'target_actor_id' => ['required', 'uuid'],
            'permission_slug' => ['required', 'string', 'max:100'],
            'reason_code' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $this->assignments->grantEventAssignment(
            $eventId, $data['target_actor_id'], $data['permission_slug'], $this->actor($request),
            $data['reason_code'], $this->correlationId($request),
        )], 201);
    }

    public function storeGate(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return response()->json(['data' => $this->assignments->createGate(
            $eventId, $data['name'], $this->actor($request), $this->correlationId($request),
        )], 201);
    }

    public function indexGates(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->assignments->listGates($eventId, $this->actor($request))]);
    }

    public function storeGateValidationAssignment(Request $request, string $gateId): JsonResponse
    {
        $data = $request->validate([
            'target_actor_id' => ['required', 'uuid'],
            'reason_code' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $this->assignments->grantGateValidation(
            $gateId, $data['target_actor_id'], $this->actor($request), $data['reason_code'], $this->correlationId($request),
        )], 201);
    }

    public function revoke(Request $request, string $assignmentId): JsonResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'max:80']]);

        return response()->json(['data' => $this->assignments->revokeAssignment(
            $assignmentId, $this->actor($request), $data['reason_code'], $this->correlationId($request),
        )]);
    }

    public function issuedBy(Request $request, string $organisationId, string $issuerActorId): JsonResponse
    {
        $data = $request->validate(['authority_type' => ['required', 'in:organisation,finance']]);

        return response()->json(['data' => $this->assignments->listAssignmentsIssuedBy(
            $organisationId, $data['authority_type'], $issuerActorId, $this->actor($request), $this->correlationId($request),
        )]);
    }

    public function delegateAdministrator(Request $request, string $organisationId): JsonResponse
    {
        $data = $request->validate([
            'target_actor_id' => ['required', 'uuid'],
            'authority_type' => ['required', 'in:organisation,finance'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->assignments->delegateAdministrator(
            $organisationId, $data['target_actor_id'], $data['authority_type'], $data['approval_reference'],
            $this->actor($request), $this->correlationId($request),
        )], 201);
    }

    public function revokeAdministrator(Request $request, string $administratorId): JsonResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'max:80']]);

        return response()->json(['data' => $this->assignments->revokeAdministrator(
            $administratorId, $this->actor($request), $data['reason_code'], $this->correlationId($request),
        )]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('identity.user_id', '');
    }

    private function correlationId(Request $request): string
    {
        $candidate = $request->header('X-Correlation-ID');

        return is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
    }
}
