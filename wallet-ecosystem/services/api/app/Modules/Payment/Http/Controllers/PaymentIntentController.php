<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\PaymentIdempotencyService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class PaymentIntentController extends Controller
{
    public function __construct(
        private readonly PaymentOrchestrationService      $orchestration,
        private readonly PaymentIntentRepositoryInterface $intentRepository,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
        private readonly PaymentIdempotencyService         $idempotency,
    ) {
    }

    /**
     * POST /v1/payment/intents — create a payment intent.
     */
    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['error' => 'Idempotency-Key header is required'], 422);
        }

        $actorId = $this->resolveActorId($request);
        if ($actorId === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        // P07: idempotency check — throws IdempotencyKeyMismatchException on hash conflict
        $idemResult = $this->idempotency->check(
            $actorId,
            'payment.intent.create',
            $idempotencyKey,
            $request->all(),
            $correlationId,
        );
        if ($idemResult->isReplay) {
            return response()->json($idemResult->responseBody, $idemResult->responseStatus);
        }

        $validator = Validator::make($request->all(), [
            'purpose_type'      => ['required', 'string', 'max:100'],
            'purpose_reference' => ['required', 'string', 'max:255'],
            'organisation_id'   => ['required', 'string', 'uuid'],
            'currency_code'     => ['required', 'string', 'size:3'],
            'metadata'          => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $intent = $this->orchestration->createIntent([
            'purpose_type'      => $validated['purpose_type'],
            'purpose_reference' => $validated['purpose_reference'],
            'organisation_id'   => $validated['organisation_id'],
            'payer_actor_id'    => $actorId,
            'currency_code'     => $validated['currency_code'],
            'idempotency_key'   => $idempotencyKey,
            'correlation_id'    => $correlationId,
            'metadata'          => $validated['metadata'] ?? [],
        ]);

        $responseBody = ['data' => $intent];
        $this->idempotency->store(
            $actorId,
            'payment.intent.create',
            $idempotencyKey,
            $request->all(),
            201,
            $responseBody,
        );

        return response()->json($responseBody, 201);
    }

    /**
     * GET /v1/payment/intents/{id}/status — retrieve full intent status with all dimensions.
     * T026: returns status, accounting_status, fulfilment_status, and latest attempt summary.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $actorId = $this->resolveActorId($request);
        if ($actorId === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $intent = $this->intentRepository->findById($id);
        if ($intent === null) {
            return response()->json(['error' => 'Payment intent not found'], 404);
        }

        // Scope: only payer can query their own intent (or admin)
        $user = $request->user();
        $hasAdminPermission = $user?->can('payment:admin') || $user?->can('payment.intents.admin');
        if ($intent->payer_actor_id !== $actorId && ! $hasAdminPermission) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        // Load latest attempt (including unknown/timed_out for status display)
        $latestAttempt     = $this->attemptRepository->findActiveForIntent($id)
            ?? $this->attemptRepository->findUnknownForIntent($id);
        $latestAttemptData = null;

        if ($latestAttempt !== null) {
            $latestAttemptData = [
                'attempt_id'    => $latestAttempt->id,
                'status'        => $latestAttempt->status instanceof \App\Modules\Payment\Domain\Enums\PaymentAttemptStatus
                    ? $latestAttempt->status->value
                    : (string) $latestAttempt->status,
                'provider_code' => $latestAttempt->provider_code,
                'operation_id'  => $latestAttempt->operation_id,
                'next_action_type' => $latestAttempt->next_action_type,
                'next_action_data' => $latestAttempt->next_action_data,
            ];
        }

        return response()->json(['data' => [
            'id'                 => $intent->id,
            'status'             => $intent->status->value,
            'accounting_status'  => $intent->accounting_status?->value,
            'fulfilment_status'  => $intent->fulfilment_status?->value,
            'amount_minor'       => $intent->amount_minor,
            'currency_code'      => $intent->currency_code,
            'purpose_type'       => $intent->purpose_type,
            'expires_at'         => $intent->expires_at?->toIso8601String(),
            'latest_attempt'     => $latestAttemptData,
        ]]);
    }

    private function resolveActorId(Request $request): ?string
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user = $request->user();

        return $user?->id;
    }
}
