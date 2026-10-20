<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\AgentFloat\Application\Services\FloatCashOutService;
use App\Modules\AgentFloat\Domain\Exceptions\FloatDailyLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatTransactionLimitExceededException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotFoundException;
use App\Modules\AgentFloat\Domain\Exceptions\FloatWalletNotActiveException;
use App\Modules\AgentFloat\Domain\Exceptions\InsufficientCustomerBalanceException;
use App\Modules\AgentFloat\Infrastructure\Http\Requests\CashOutRequest;
use App\Modules\AgentFloat\Infrastructure\Http\Resources\FloatTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentFloatCashOutController extends Controller
{
    public function __construct(
        private readonly FloatCashOutService $cashOutService,
    ) {}

    /**
     * @OA\Post(
     *   path="/v1/agents/{agentId}/customers/{customerId}/cash-out",
     *   summary="Agent performs cash-out for a linked customer",
     *   tags={"Agent Float"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="agentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="customerId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Idempotency-Key", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/CashOutRequest")),
     *   @OA\Response(response=201, description="Cash-out completed", @OA\JsonContent(ref="#/components/schemas/FloatTransactionResponse")),
     *   @OA\Response(response=404, description="Float wallet not found"),
     *   @OA\Response(response=422, description="Insufficient customer balance, wallet not active, or limit exceeded")
     * )
     */
    public function cashOut(CashOutRequest $request, string $agentId, string $customerId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $validated = $request->validated();

        try {
            $txn = $this->cashOutService->cashOut(
                agentId:          $agentId,
                customerId:       $customerId,
                customerWalletId: $validated['customer_wallet_id'],
                amount:           $validated['amount'],
                currencyCode:     $validated['currency_code'],
                actorId:          $actorId,
                idempotencyKey:   $idempotencyKey,
                correlationId:    $correlationId,
            );
        } catch (FloatWalletNotFoundException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 404, $correlationId);
        } catch (FloatWalletNotActiveException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        } catch (InsufficientCustomerBalanceException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        } catch (FloatTransactionLimitExceededException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        } catch (FloatDailyLimitExceededException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new FloatTransactionResource($txn))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 201, ['X-Correlation-ID' => $correlationId]);
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
