<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventCancellationController extends Controller
{
    public function __construct(private readonly EventCancellationService $cancellations) {}

    public function request(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->cancellations->request(
            $eventId, $this->actor($request), (int) $data['expected_version'], $data['reason'],
            (string) $request->header('Idempotency-Key', ''), $this->correlationId($request),
        )], 202);
    }

    public function requests(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->cancellations->requests($eventId, $this->actor($request))]);
    }

    public function approve(Request $request, string $eventId, string $requestId): JsonResponse
    {
        return $this->review($request, $eventId, $requestId, true);
    }

    public function reject(Request $request, string $eventId, string $requestId): JsonResponse
    {
        return $this->review($request, $eventId, $requestId, false);
    }

    private function review(Request $request, string $eventId, string $requestId, bool $approve): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->cancellations->review(
            $eventId, $requestId, $this->actor($request), (int) $data['expected_version'], $approve,
            $data['reason'] ?? null, (string) $request->header('Idempotency-Key', ''), $this->correlationId($request),
        )]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');

        return $actor;
    }

    private function correlationId(Request $request): ?string
    {
        $id = $request->header('X-Correlation-ID');

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }
}
