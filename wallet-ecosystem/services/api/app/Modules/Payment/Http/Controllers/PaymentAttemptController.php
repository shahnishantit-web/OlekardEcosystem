<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\PaymentIdempotencyService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use App\Modules\Payment\Domain\Exceptions\UnsafeProviderSwitchException;
use App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class PaymentAttemptController extends Controller
{
    public function __construct(
        private readonly PaymentOrchestrationService       $orchestration,
        private readonly PaymentIntentRepositoryInterface  $intentRepository,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
        private readonly PaymentIdempotencyService          $idempotency,
    ) {
    }

    /**
     * GET /v1/payment/intents/{intentId}/attempts/{attemptId} — retrieve attempt details.
     * T025: includes operation_id, quote_id, quote_version, reconciliation_exception_id.
     */
    public function show(Request $request, string $intentId, string $attemptId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $intent = $this->intentRepository->findById($intentId);
        if ($intent === null) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        $hasAdminPermission = $user->can('payment:admin') || $user->can('payment.intents.admin');
        if ($intent->payer_actor_id !== $user->id && ! $hasAdminPermission) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $attempt = $this->attemptRepository->findById($attemptId);
        if ($attempt === null || $attempt->payment_intent_id !== $intentId) {
            return response()->json(['error' => 'Payment attempt not found'], 404);
        }

        // Load most recent reconciliation exception for this attempt
        $exception = ReconciliationExceptionModel::query()
            ->where('payment_attempt_id', $attemptId)
            ->orderByDesc('created_at')
            ->first();

        return response()->json(['data' => [
            'attempt_id'               => $attempt->id,
            'payment_intent_id'        => $attempt->payment_intent_id,
            'provider_code'            => $attempt->provider_code,
            'status'                   => $attempt->status instanceof \App\Modules\Payment\Domain\Enums\PaymentAttemptStatus
                ? $attempt->status->value
                : (string) $attempt->status,
            'operation_id'             => $attempt->operation_id,
            'quote_id'                 => $attempt->quote_id,
            'quote_version'            => $attempt->quote_version,
            'provider_reference_id'    => $attempt->provider_reference_id,
            'next_action_type'         => $attempt->next_action_type,
            'next_action_data'         => $attempt->next_action_data,
            'fee_minor'                => $attempt->fee_minor,
            'wallet_hold_id'           => $attempt->wallet_hold_id,
            'reconciliation_exception_id' => $exception?->id,
            'initiated_at'             => $attempt->initiated_at?->toIso8601String(),
            'confirmed_at'             => $attempt->confirmed_at?->toIso8601String(),
            'failed_at'                => $attempt->failed_at?->toIso8601String(),
            'failure_reason'           => $attempt->failure_reason,
        ]]);
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

        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        // P07: idempotency check — throws on hash conflict or in-progress
        $idemResult = $this->idempotency->check(
            $user->id,
            'payment.attempt.initiate',
            $idempotencyKey,
            $request->all(),
            $correlationId,
        );
        if ($idemResult->isReplay) {
            return response()->json($idemResult->responseBody, $idemResult->responseStatus);
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
                'correlation_id'      => $correlationId,
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
        } catch (UnsafeProviderSwitchException $e) {
            return response()->json([
                'error'             => 'Cannot switch provider while a prior attempt outcome is unresolved',
                'code'              => 'UNSAFE_PROVIDER_SWITCH',
                'unknown_provider'  => $e->unknownProvider,
                'requested_provider' => $e->requestedProvider,
            ], 409);
        }

        $responseBody = ['data' => $result];
        $this->idempotency->store(
            $user->id,
            'payment.attempt.initiate',
            $idempotencyKey,
            $request->all(),
            201,
            $responseBody,
        );

        return response()->json($responseBody, 201);
    }

    /**
     * POST /v1/payment/intents/{intentId}/attempts/{attemptId}/outcome — record provider outcome.
     */
    public function outcome(Request $request, string $intentId, string $attemptId): JsonResponse
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

        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        // P07: idempotency check — throws on hash conflict or in-progress
        $idemResult = $this->idempotency->check(
            $user->id,
            'payment.outcome',
            $idempotencyKey,
            $request->all(),
            $correlationId,
        );
        if ($idemResult->isReplay) {
            return response()->json($idemResult->responseBody, $idemResult->responseStatus);
        }

        $validator = Validator::make($request->all(), [
            'outcome'               => ['required', 'string', 'in:succeeded,failed,cancelled'],
            'provider_reference_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'failure_reason'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'fee_minor'             => ['sometimes', 'integer', 'min:0'],
            'captured_amount_minor' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $this->orchestration->handleOutcome([
            'payment_intent_id'     => $intentId,
            'payment_attempt_id'    => $attemptId,
            'outcome'               => $validated['outcome'],
            'provider_reference_id' => $validated['provider_reference_id'] ?? null,
            'failure_reason'        => $validated['failure_reason'] ?? null,
            'fee_minor'             => $validated['fee_minor'] ?? 0,
            'captured_amount_minor' => $validated['captured_amount_minor'] ?? null,
            'correlation_id'        => $correlationId,
        ]);

        $responseBody = ['data' => ['status' => 'accepted']];
        $this->idempotency->store(
            $user->id,
            'payment.outcome',
            $idempotencyKey,
            $request->all(),
            200,
            $responseBody,
        );

        return response()->json($responseBody);
    }
}
