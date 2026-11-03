<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventAdmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventAdmissionController extends Controller
{
    public function __construct(private readonly EventAdmissionService $admission) {}

    public function validate(Request $request, string $eventId, string $gateId): JsonResponse
    {
        $data = $request->validate(['credential' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/']]);
        $result = $this->admission->validateOnline(
            $eventId, $gateId, $this->operator($request), $data['credential'], $this->correlationId($request),
        );

        // Contract intentionally exposes no identity, ticket ID, payment, or credential data.
        return response()->json(['data' => $result]);
    }

    public function tickets(Request $request): JsonResponse
    {
        return $this->privateResponse(['data' => $this->admission->ticketsForPurchaser($this->purchaser($request))]);
    }

    public function ticket(Request $request, string $ticketId): JsonResponse
    {
        return $this->privateResponse(['data' => $this->admission->ticketForPurchaser($ticketId, $this->purchaser($request))]);
    }

    public function replaceCredential(Request $request, string $ticketId): JsonResponse
    {
        $data = $request->validate(['step_up_token' => ['required', 'string', 'max:4096']]);
        $actorId = $this->purchaser($request);
        $sessionId = (string) $request->attributes->get('identity.session_id', '');
        abort_unless(Str::isUuid($sessionId), 401, 'Authenticated session context is required.');

        return response()->json(['data' => $this->admission->replaceCredential(
            $ticketId, $actorId, $sessionId, $data['step_up_token'], (string) $request->header('Idempotency-Key', ''),
        )]);
    }

    public function attendance(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->admission->aggregateAttendance($eventId, $this->operator($request))]);
    }

    private function purchaser(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor) && strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER',
            401, 'Authenticated customer context is required.');

        return $actor;
    }

    private function operator(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated operator context is required.');

        return $actor;
    }

    private function correlationId(Request $request): ?string
    {
        $candidate = $request->header('X-Correlation-ID');

        return is_string($candidate) && Str::isUuid($candidate) ? $candidate : null;
    }

    /** Credential-bearing responses must never enter a shared or browser cache. */
    private function privateResponse(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
