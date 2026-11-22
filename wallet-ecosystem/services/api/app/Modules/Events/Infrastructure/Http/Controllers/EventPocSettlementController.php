<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventPocSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventPocSettlementController extends Controller
{
    public function __construct(private readonly EventPocSettlementService $settlements) {}

    public function index(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->list($eventId, $this->actor($request))]);
    }

    public function refunds(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->refunds($eventId, $this->actor($request))]);
    }

    public function eventEnd(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->eventEnd($eventId, $this->actor($request))]);
    }

    public function simulateEventEnd(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->simulateEventEnd(
            $eventId, $this->actor($request), (string) $request->header('Idempotency-Key', ''),
        )], 201);
    }

    public function request(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->request($eventId, $this->actor($request), (string) $request->header('Idempotency-Key', ''))], 201);
    }

    public function approve(Request $request, string $settlementId): JsonResponse
    {
        return response()->json(['data' => $this->settlements->approve($settlementId, $this->actor($request), (string) $request->header('Idempotency-Key', ''))]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');
        return $actor;
    }
}
