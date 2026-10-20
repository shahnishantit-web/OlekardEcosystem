<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class PaymentAttemptController extends Controller
{
    public function __construct(
        private readonly PaymentOrchestrationService      $orchestration,
        private readonly PaymentIntentRepositoryInterface $intentRepository,
    ) {
    }

    /**
     * POST /v1/payment/intents/{id}/attempts — initiate a payment attempt.
     */
    public function store(Request $request, string $intentId): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['error' => 'Idempotency-Key header is required'], 422);
        }

        // Verify intent ownership before proceeding
        $intent = $this->intentRepository->findById($intentId);
        if ($intent === null) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        if ($intent->payer_actor_id !== $user->id) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $validator = Validator::make($request->all(), [
            'provider_code'       => ['required', 'string', 'max:100'],
            'wallet_id'           => ['sometimes', 'string', 'uuid'],
            'step_up_token'       => ['sometimes', 'string'],
            'provider_account_id' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        try {
            $result = $this->orchestration->initiateAttempt([
                'payment_intent_id'   => $intentId,
                'provider_code'       => $validated['provider_code'],
                'payer_actor_id'      => $user->id,
                'idempotency_key'     => $idempotencyKey,
                'correlation_id'      => $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7(),
                'wallet_id'           => $validated['wallet_id'] ?? null,
                'step_up_token'       => $validated['step_up_token'] ?? null,
                'provider_account_id' => $validated['provider_account_id'] ?? null,
            ]);
        } catch (PaymentIntentNotFoundException) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        } catch (DuplicateAttemptException) {
            return response()->json(['error' => 'An active attempt already exists for this payment intent'], 409);
        } catch (StepUpRequiredException $e) {
            return response()->json([
                'error'          => 'Step-up authentication required',
                'code'           => 'STEP_UP_REQUIRED',
                'challenge_type' => $e->getChallengeType(),
                'payment_intent_id' => $e->getPaymentIntentId(),
            ], 403);
        } catch (InsufficientFundsException $e) {
            return response()->json([
                'error'             => 'Insufficient funds',
                'available_minor'   => $e->getAvailableMinor(),
                'required_minor'    => $e->getRequiredMinor(),
                'currency_code'     => $e->getCurrencyCode(),
            ], 422);
        }

        return response()->json(['data' => $result], 201);
    }
}
