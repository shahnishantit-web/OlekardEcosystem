<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Application\Services\FloatTransactionService;
use App\Modules\AgentFloat\Infrastructure\Http\Requests\ListFloatTransactionsRequest;
use App\Modules\AgentFloat\Infrastructure\Http\Resources\FloatTransactionResource;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentFloatTransactionController extends Controller
{
    public function __construct(
        private readonly FloatTransactionService $transactionService,
        private readonly IdentityModuleContract $identity,
    ) {}

    /**
     * @OA\Get(
     *   path="/v1/agents/{agentId}/float-wallet/transactions",
     *   summary="List float transaction history for an agent",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20)),
     *   @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"funding","cash_in","cash_out"})),
     *   @OA\Response(response=200, description="Paginated transaction list", @OA\JsonContent(ref="#/components/schemas/FloatTransactionListResponse"))
     * )
     */
    public function index(ListFloatTransactionsRequest $request, string $agentId): JsonResponse
    {
        $this->guardAgentAccess($request, $agentId);
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $validated     = $request->validated();

        $result = $this->transactionService->list(
            agentId: $agentId,
            page:    (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 20),
            from:    $validated['from'] ?? null,
            to:      $validated['to'] ?? null,
            type:    $validated['type'] ?? null,
            status:  $validated['status'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data'    => FloatTransactionResource::collection($result['data'])->toArray($request),
            'error'   => null,
            'meta'    => $result['meta'],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function show(ListFloatTransactionsRequest $request, string $agentId, string $transactionId): JsonResponse
    {
        $this->guardAgentAccess($request, $agentId);
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $transaction = $this->transactionService->find($agentId, $transactionId);
        if ($transaction === null) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource does not exist.', 'details' => []],
                'correlation_id' => $correlationId,
                'meta' => null,
            ], 404, ['X-Correlation-ID' => $correlationId]);
        }

        return response()->json([
            'success' => true,
            'data' => (new FloatTransactionResource($transaction))->toArray($request),
            'error' => null,
            'meta' => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function guardAgentAccess(ListFloatTransactionsRequest $request, string $agentId): void
    {
        $actorId = (string) $request->attributes->get('identity.user_id');
        if ($actorId === '') {
            abort(401);
        }
        if ($this->identity->hasPermission($actorId, 'agent_float.transactions.view_any')) {
            return;
        }
        if (! AgentModel::query()->where('id', $agentId)->where('user_id', $actorId)->exists()) {
            abort(403);
        }
    }
}
