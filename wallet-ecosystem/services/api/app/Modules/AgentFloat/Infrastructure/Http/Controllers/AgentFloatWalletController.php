<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\AgentFloat\Application\Services\FloatWalletProvisioningService;
use App\Modules\AgentFloat\Domain\Exceptions\AgentNotEligibleForFloatException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletAlreadyExistsException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotFoundException;
use App\Modules\AgentFloat\Infrastructure\Http\Requests\CreateFloatWalletRequest;
use App\Modules\AgentFloat\Infrastructure\Http\Resources\FloatWalletResource;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentFloatWalletController extends Controller
{
    public function __construct(
        private readonly FloatWalletProvisioningService $provisioningService,
        private readonly LedgerModuleContract           $ledger,
    ) {}

    /**
     * @OA\Post(
     *   path="/v1/agents/{agentId}/float-wallet",
     *   summary="Provision an agent float wallet",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Idempotency-Key", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/CreateFloatWalletRequest")),
     *   @OA\Response(response=201, description="Float wallet created", @OA\JsonContent(ref="#/components/schemas/FloatWalletResponse")),
     *   @OA\Response(response=200, description="Idempotent replay", @OA\JsonContent(ref="#/components/schemas/FloatWalletResponse")),
     *   @OA\Response(response=409, description="Float wallet already exists"),
     *   @OA\Response(response=422, description="Validation error or agent not eligible")
     * )
     */
    public function create(CreateFloatWalletRequest $request, string $agentId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $isReplay = false;

        try {
            $floatWallet = $this->provisioningService->provision(
                agentId:        $agentId,
                currencyCode:   $request->validated()['currency_code'],
                actorId:        $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (FloatWalletAlreadyExistsException $e) {
            // Check if this is an idempotent replay — idempotency handler returns the wallet
            $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
            if ($floatWallet === null) {
                return $this->errorResponse($e->errorCode, $e->getMessage(), 409, $correlationId);
            }
            $isReplay = true;
        } catch (AgentNotEligibleForFloatException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        }

        $walletModel = WalletModel::find($floatWallet->wallet_id);
        $limits      = FloatLimitModel::where('agent_id', $agentId)->first();

        $status = $isReplay ? 200 : 201;

        return response()->json([
            'success' => true,
            'data'    => (new FloatWalletResource($floatWallet, $this->ledger, $walletModel, $limits))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Get(
     *   path="/v1/agents/{agentId}/float-wallet",
     *   summary="View agent float wallet balance and status",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="Float wallet details", @OA\JsonContent(ref="#/components/schemas/FloatWalletResponse")),
     *   @OA\Response(response=404, description="Float wallet not found")
     * )
     */
    public function show(string $agentId): JsonResponse
    {
        $correlationId = (string) Str::uuid();

        $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();
        if ($floatWallet === null) {
            return $this->errorResponse('FLOAT_WALLET_NOT_FOUND', "No float wallet for agent [{$agentId}].", 404, $correlationId);
        }

        $walletModel = WalletModel::find($floatWallet->wallet_id);
        $limits      = FloatLimitModel::where('agent_id', $agentId)->first();

        return response()->json([
            'success' => true,
            'data'    => (new FloatWalletResource($floatWallet, $this->ledger, $walletModel, $limits))->toArray(request()),
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
