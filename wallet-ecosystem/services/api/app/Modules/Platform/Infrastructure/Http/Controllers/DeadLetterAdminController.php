<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Controllers;

use App\Modules\Platform\Application\Services\DeadLetterService;
use App\Modules\Platform\Domain\Contracts\DeadLetterServiceInterface;
use App\Modules\Platform\Domain\Exceptions\DeadLetterConflictException;
use App\Modules\Platform\Infrastructure\Http\Requests\DismissDeadLetterRequest;
use App\Modules\Platform\Infrastructure\Http\Requests\ReplayDeadLetterRequest;
use App\Modules\Platform\Infrastructure\Http\Resources\DeadLetterItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class DeadLetterAdminController extends Controller
{
    public function __construct(private readonly DeadLetterServiceInterface $deadLetterService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $filters = array_filter([
            'status'         => $request->query('status'),
            'operation_type' => $request->query('operation_type'),
            'from'           => $request->query('from'),
            'to'             => $request->query('to'),
        ]);

        $paginator = $this->deadLetterService->list($filters, $perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'      => DeadLetterItemResource::collection($paginator)->toArray($request),
                'pagination' => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->deadLetterService->findById($id);

        if ($item === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'DEAD_LETTER_NOT_FOUND', 'message' => 'Dead-letter item not found.'],
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data'    => (new DeadLetterItemResource($item))->toArray($request),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->deadLetterService->stats(),
        ]);
    }

    public function replay(ReplayDeadLetterRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $actorId       = $request->user()?->id ?? '';

        try {
            $item = $this->deadLetterService->replay($id, $actorId, $request->input('note'), $correlationId);
        } catch (DeadLetterConflictException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'DEAD_LETTER_CONFLICT', 'message' => $e->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'data'    => (new DeadLetterItemResource($item))->toArray($request),
        ]);
    }

    public function dismiss(DismissDeadLetterRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $actorId       = $request->user()?->id ?? '';

        try {
            $item = $this->deadLetterService->dismiss($id, $actorId, $request->input('reason'), $correlationId);
        } catch (DeadLetterConflictException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'DEAD_LETTER_CONFLICT', 'message' => $e->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'data'    => (new DeadLetterItemResource($item))->toArray($request),
        ]);
    }
}
