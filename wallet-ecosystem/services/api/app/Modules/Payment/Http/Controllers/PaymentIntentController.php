<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\PaymentOrchestrationService;
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
    ) {
    }

    /**
     * POST /v1/payment/intents — create a payment intent.
     */
    public function store(Request $request): JsonResponse
    {
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

        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['error' => 'Idempotency-Key header is required'], 422);
        }

        $actorId = $this->resolveActorId($request);
        if ($actorId === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $validator->validated();

        $intent = $this->orchestration->createIntent([
            'purpose_type'      => $validated['purpose_type'],
            'purpose_reference' => $validated['purpose_reference'],
            'organisation_id'   => $validated['organisation_id'],
            'payer_actor_id'    => $actorId,
            'currency_code'     => $validated['currency_code'],
            'idempotency_key'   => $idempotencyKey,
            'correlation_id'    => $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7(),
            'metadata'          => $validated['metadata'] ?? [],
        ]);

        return response()->json(['data' => $intent], 201);
    }

    /**
     * GET /v1/payment/intents/{id}/status — retrieve intent status.
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

        // Scope: only payer can query their own intent
        if ($intent->payer_actor_id !== $actorId) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        return response()->json(['data' => [
            'id'            => $intent->id,
            'status'        => $intent->status->value,
            'amount_minor'  => $intent->amount_minor,
            'currency_code' => $intent->currency_code,
            'purpose_type'  => $intent->purpose_type,
            'expires_at'    => $intent->expires_at?->toIso8601String(),
        ]]);
    }

    private function resolveActorId(Request $request): ?string
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user = $request->user();

        return $user?->id;
    }
}
