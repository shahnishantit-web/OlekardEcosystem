<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\OutboxDeadLetterServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxQueueHealthServiceInterface;
use App\Modules\Platform\Domain\Exceptions\OutboxConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class OutboxAdminController extends Controller
{
    public function __construct(
        private readonly OutboxDeadLetterServiceInterface  $deadLetterService,
        private readonly OutboxQueueHealthServiceInterface $healthService,
    ) {}

    public function deadLetters(Request $request): JsonResponse
    {
        $filters = array_filter([
            'event_name'     => $request->query('event_name'),
            'correlation_id' => $request->query('correlation_id'),
        ]);
        $perPage = min((int) $request->query('per_page', 20), 100);

        return response()->json($this->deadLetterService->listDeadLetters($filters, $perPage));
    }

    public function replay(string $id, Request $request): JsonResponse
    {
        try {
            $event = $this->deadLetterService->replay($id, (string) ($request->user()?->id ?? ''));
        } catch (ModelNotFoundException) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => "Outbox event [{$id}] not found."],
            ], Response::HTTP_NOT_FOUND);
        } catch (OutboxConflictException $e) {
            return response()->json([
                'error' => ['code' => 'conflict', 'message' => $e->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => [
                'id'          => $event->getAttribute('id'),
                'event_name'  => $event->getAttribute('event_name'),
                'status'      => $event->getAttribute('status'),
                'attempts'    => $event->getAttribute('attempts'),
                'replayed_by' => $request->user()?->id,
                'replayed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $metrics = $this->healthService->getHealthMetrics($request->query('event_name'));

        return response()->json([
            'data' => $metrics,
            'meta' => ['queried_at' => now()->toIso8601String()],
        ]);
    }
}
