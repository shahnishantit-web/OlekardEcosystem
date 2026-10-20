<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Modules\Security\Application\Services\VulnerabilityFindingService;
use App\Modules\Security\Domain\Enums\VulnerabilityFindingStatus;
use App\Modules\Security\Http\Requests\CreateFindingRequest;
use App\Modules\Security\Http\Requests\UpdateFindingStatusRequest;
use App\Modules\Security\Http\Resources\VulnerabilityFindingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class SecurityAdminController extends Controller
{
    public function __construct(
        private readonly VulnerabilityFindingService $service,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $counts = $this->service->dashboard();

        return response()->json(['data' => $counts]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['severity', 'status', 'scanner']);
        $perPage = (int) $request->query('per_page', '20');

        $paginator = $this->service->list($filters, min($perPage, 100));

        return response()->json([
            'data' => VulnerabilityFindingResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(CreateFindingRequest $request): JsonResponse
    {
        $idempotencyKey = $request->idempotencyKey();
        $actorId = (string) $request->attributes->get('identity.user_id', $request->user()?->getAuthIdentifier());

        $model = $this->service->create(
            $request->validated(),
            $idempotencyKey,
            $actorId,
        );

        return response()->json(
            ['data' => new VulnerabilityFindingResource($model)],
            201,
        );
    }

    public function show(string $id): JsonResponse
    {
        $model = $this->service->findById($id);

        if ($model === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['data' => new VulnerabilityFindingResource($model)]);
    }

    public function updateStatus(UpdateFindingStatusRequest $request, string $id): JsonResponse
    {
        $actorId = (string) $request->attributes->get('identity.user_id', $request->user()?->getAuthIdentifier());
        $correlationId = (string) $request->header('X-Correlation-ID', (string) Uuid::uuid7());
        $status = VulnerabilityFindingStatus::from($request->validated()['status']);
        $justification = $request->validated()['suppression_justification'] ?? null;

        try {
            $model = $this->service->updateStatus($id, $status, $justification, $actorId, $correlationId);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['code' => 'INVALID_FINDING_TRANSITION', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => new VulnerabilityFindingResource($model)]);
    }
}
