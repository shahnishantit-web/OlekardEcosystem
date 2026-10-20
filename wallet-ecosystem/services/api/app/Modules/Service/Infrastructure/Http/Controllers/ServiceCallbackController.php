<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Infrastructure\Models\ServiceProviderCallbackModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceCallbackController extends Controller
{
    public function __construct(
        private readonly ServiceProviderAdapter $adapter,
        private readonly ServiceOutcomeService  $outcome,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $payload = $request->all();

        // Store raw callback (UNIQUE on provider_code + provider_reference — duplicate = idempotent)
        $providerReference = $payload['provider_reference'] ?? '';
        $existing = ServiceProviderCallbackModel::where('provider_code', $provider)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($existing !== null && $existing->processed) {
            return response()->json(['message' => 'Already processed.'], 200);
        }

        $callback = $existing ?? ServiceProviderCallbackModel::create([
            'id'                 => Str::orderedUuid()->toString(),
            'provider_code'      => $provider,
            'provider_reference' => $providerReference,
            'transaction_id'     => $payload['transaction_id'] ?? null,
            'payload'            => $payload,
            'verified'           => false,
            'processed'          => false,
            'received_at'        => now(),
        ]);

        try {
            $result = $this->adapter->verifyCallback($provider, $payload, $request->headers->all());

            $callback->verified = $result->verified;
            $callback->save();

            if (! $result->verified) {
                $callback->processing_error = 'Signature verification failed';
                $callback->save();
                return response()->json(['message' => 'OK'], 200);
            }

            $txId          = $result->transactionId;
            $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());

            match ($result->status) {
                'success', 'completed' => $this->outcome->handleSuccess($txId, $result->providerReference, $correlationId),
                'failure', 'failed'    => $this->outcome->handleFailure($txId, $payload['reason'] ?? 'provider_failure', $correlationId),
                default                => $this->outcome->handleAmbiguous($txId, $correlationId),
            };

            $callback->processed    = true;
            $callback->processed_at = now();
            $callback->save();

        } catch (\Throwable $e) {
            $callback->processing_error = $e->getMessage();
            $callback->save();
        }

        // Always return 200 — provider must never retry on our errors
        return response()->json(['message' => 'OK'], 200);
    }
}
