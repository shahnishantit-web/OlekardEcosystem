<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\AgentFloat\Application\Services\FloatLimitService;
use App\Modules\AgentFloat\Infrastructure\Http\Requests\UpdateFloatLimitsRequest;
use App\Modules\AgentFloat\Infrastructure\Http\Resources\FloatLimitResource;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentFloatLimitController extends Controller
{
    public function __construct(
        private readonly FloatLimitService $limitService,
    ) {}

    /**
     * @OA\Patch(
     *   path="/v1/agents/{agentId}/float-wallet/limits",
     *   summary="Update float limits for an agent",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/UpdateFloatLimitsRequest")),
     *   @OA\Response(response=200, description="Limits updated", @OA\JsonContent(ref="#/components/schemas/FloatLimitResponse")),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateFloatLimitsRequest $request, string $agentId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');
        $validated     = $request->validated();

        if (empty($validated)) {
            return $this->errorResponse('VALIDATION_ERROR', 'At least one limit field must be provided.', 422, $correlationId);
        }

        $limits = $this->limitService->updateLimits($agentId, $validated, $actorId, $correlationId);

        return response()->json([
            'success' => true,
            'data'    => (new FloatLimitResource($limits))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Get(
     *   path="/v1/agents/{agentId}/float-wallet/limits",
     *   summary="View float limits for an agent",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="Float limits", @OA\JsonContent(ref="#/components/schemas/FloatLimitResponse")),
     *   @OA\Response(response=404, description="Float limits not found")
     * )
     */
    public function show(string $agentId): JsonResponse
    {
        $correlationId = (string) Str::uuid();

        $limits = FloatLimitModel::where('agent_id', $agentId)->first();
        if ($limits === null) {
            return $this->errorResponse('FLOAT_LIMITS_NOT_FOUND', "No float limits configured for agent [{$agentId}].", 404, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new FloatLimitResource($limits))->toArray(request()),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
