<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\FreeEventRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class FreeEventRegistrationController extends Controller
{
    public function __construct(private readonly FreeEventRegistrationService $registrations) {}

    public function createInvitation(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'recipient_actor_id' => ['required', 'uuid'],
            'expires_at' => ['required', 'date'],
            'max_uses' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        abort_unless($idempotencyKey !== '', 422, 'A valid Idempotency-Key is required.');

        return response()->json(['data' => $this->registrations->createInvitation(
            $eventId, $data['category_id'], $data['recipient_actor_id'], $data['expires_at'],
            (int) $data['max_uses'], $this->actor($request), $idempotencyKey, $this->correlationId($request),
        )], 201)->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function revokeInvitation(Request $request, string $invitationId): JsonResponse
    {
        return response()->json(['data' => $this->registrations->revokeInvitation(
            $invitationId, $this->actor($request), $this->correlationId($request),
        )]);
    }

    public function register(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'invitation_token' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $actorId = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actorId)
            && strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER',
            401, 'Authenticated customer context is required.');
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        abort_unless($idempotencyKey !== '', 422, 'A valid Idempotency-Key is required.');

        return response()->json(['data' => $this->registrations->register(
            $eventId, $data['category_id'], $actorId, $data['invitation_token'] ?? null,
            $idempotencyKey, $this->correlationId($request),
        )], 201)->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function actor(Request $request): string
    {
        $actorId = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actorId), 401, 'Authenticated actor context is required.');

        return $actorId;
    }

    private function correlationId(Request $request): string
    {
        $candidate = $request->header('X-Correlation-ID');

        return is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Uuid::uuid7();
    }
}
