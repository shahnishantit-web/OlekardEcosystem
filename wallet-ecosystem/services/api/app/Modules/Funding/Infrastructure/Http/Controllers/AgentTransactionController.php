<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\AgentCashInService;
use App\Modules\Funding\Infrastructure\Http\Requests\AgentCashInRequest;
use App\Modules\Funding\Infrastructure\Http\Resources\FundingTransactionResource;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AgentTransactionController extends Controller
{
    public function __construct(
        private readonly AgentCashInService        $agentCashInService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * POST /api/v1/funding/agent-transactions
     *
     * Agent-initiated cash-in. Agent must have `funding.agent-cashin` permission.
     * Idempotency: X-Idempotency-Key header required.
     */
    public function cashIn(AgentCashInRequest $request): JsonResponse
    {
        $agentId        = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId  = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();
        $idempotencyKey = $request->header('X-Idempotency-Key', (string) \Ramsey\Uuid\Uuid::uuid7());

        // Idempotency replay
        $cached = $this->idempotency->findCompletedResponse('funding:' . $idempotencyKey, $agentId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status_code']);
        }

        $tx = $this->agentCashInService->cashIn([
            'agent_id'       => $agentId,
            'customer_id'    => $request->input('customer_id'),
            'wallet_id'      => $request->input('wallet_id'),
            'amount'         => (int) $request->input('amount'),
            'currency_code'  => strtoupper($request->input('currency_code')),
            'pin'            => $request->input('transaction_pin'),
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $responseBody = (new FundingTransactionResource($tx))->toArray($request);

        $lock = $this->idempotency->acquireLock(
            'funding:' . $idempotencyKey,
            $agentId,
            md5(json_encode($request->validated())),
            'POST',
            $request->path(),
            $correlationId,
        );
        $this->idempotency->recordResponse($lock, 201, $responseBody);

        return response()->json(['data' => $responseBody], 201);
    }

    /**
     * GET /api/v1/funding/agent-transactions
     *
     * Returns agent's cash-in history (method = agent only).
     */
    public function index(Request $request): JsonResponse
    {
        $agentId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $transactions = FundingTransactionModel::where('agent_id', $agentId)
            ->where('funding_method', FundingMethod::Agent->value)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => FundingTransactionResource::collection($transactions)->toArray($request),
            'meta' => [
                'total'    => $transactions->total(),
                'page'     => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'pages'    => $transactions->lastPage(),
            ],
        ]);
    }
}
