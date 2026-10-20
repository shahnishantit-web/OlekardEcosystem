<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Infrastructure\Http\Requests\InitiateServicePaymentRequest;
use App\Modules\Service\Infrastructure\Http\Resources\ServiceTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ServicePaymentController extends Controller
{
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $customerId = (string) $request->attributes->get('identity.user_id');
        $payments = \App\Modules\Service\Infrastructure\Models\ServiceTransactionModel::query()
            ->where('customer_id', $customerId)->orderByDesc('created_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));
        return ServiceTransactionResource::collection($payments);
    }

    public function __construct(private readonly ServicePaymentService $payment) {}

    public function store(InitiateServicePaymentRequest $request, string $id): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $customerId = (string) $request->attributes->get('identity.user_id');

        $validated                  = $request->validated();
        $validated['service_id']    = $id;
        $validated['idempotency_key'] = $idempotencyKey;
        $validated['session_id'] = (string) $request->attributes->get('identity.session_id');

        // Enforce wallet ownership
        if (empty($validated['wallet_id'])) {
            return response()->json(['message' => 'wallet_id is required.'], 422);
        }

        $tx = $this->payment->initiate(
            data:          $validated,
            customerId:    $customerId,
            correlationId: $request->header('X-Correlation-Id', (string) Str::uuid()),
        );

        return (new ServiceTransactionResource($tx))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $txId): ServiceTransactionResource
    {
        $customerId = (string) $request->attributes->get('identity.user_id');

        return new ServiceTransactionResource(
            $this->payment->findTransaction($txId, $customerId)
        );
    }
}
