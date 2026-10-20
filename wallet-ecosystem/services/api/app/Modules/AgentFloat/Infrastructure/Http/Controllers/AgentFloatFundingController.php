<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\AgentFloat\Application\Services\FloatFundingService;
use App\Modules\AgentFloat\Domain\Exceptions\FloatFundingAccountNotConfiguredException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotFoundException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotActiveException;
use App\Modules\AgentFloat\Infrastructure\Http\Requests\FundFloatWalletRequest;
use App\Modules\AgentFloat\Infrastructure\Http\Resources\FloatTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentFloatFundingController extends Controller
{
    public function __construct(
        private readonly FloatFundingService $fundingService,
    ) {}

    /**
     * @OA\Post(
     *   path="/v1/agents/{agentId}/float-wallet/fund",
     *   summary="Admin funds an agent float wallet",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Idempotency-Key", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/FundFloatWalletRequest")),
     *   @OA\Response(response=201, description="Float wallet funded", @OA\JsonContent(ref="#/components/schemas/FloatTransactionResponse")),
     *   @OA\Response(response=200, description="Idempotent replay"),
     *   @OA\Response(response=404, description="Float wallet not found"),
     *   @OA\Response(response=422, description="Wallet not active or limit exceeded"),
     *   @OA\Response(response=503, description="Funding account not configured")
     * )
     */
    public function fund(FundFloatWalletRequest $request, string $agentId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $validated = $request->validated();

        try {
            $txn = $this->fundingService->fund(
                agentId:        $agentId,
                amount:         $validated['amount'],
                currencyCode:   $validated['currency_code'],
                actorId:        $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (FloatWalletNotFoundException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 404, $correlationId);
        } catch (FloatWalletNotActiveException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        } catch (FloatLimitExceededException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        } catch (FloatFundingAccountNotConfiguredException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 503, $correlationId);
        }

        $status = 201;

        return response()->json([
            'success' => true,
            'data'    => (new FloatTransactionResource($txn))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
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
