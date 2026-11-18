<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventInventoryHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class EventInventoryHoldController extends Controller
{
    public function __construct(private readonly EventInventoryHoldService $holds) {}

    public function store(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        $actorId = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actorId)
            && strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER',
            401, 'Authenticated customer context is required.');
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        abort_unless($idempotencyKey !== '', 422, 'Idempotency-Key header is required.');
        $correlationId = $request->header('X-Correlation-ID');
        if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
            $correlationId = (string) Uuid::uuid7();
        }

        return response()->json(['data' => $this->holds->createHold(
            $eventId, $data['category_id'], $actorId, (int) $data['quantity'], $idempotencyKey, $correlationId,
        )], 201)->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
