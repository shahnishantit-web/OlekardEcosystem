<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\ProviderCallbackService;
use App\Modules\Funding\Domain\Exceptions\DuplicateCallbackException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ProviderCallbackController extends Controller
{
    public function __construct(
        private readonly ProviderCallbackService $callbackService,
    ) {}

    /**
     * POST /api/v1/funding/callbacks/{provider}
     *
     * Public endpoint. HMAC verification is done inline by ProviderCallbackService.
     * Returns 200 before any queued work so the provider doesn't retry.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();
        $signature     = $request->header('X-Signature', '');

        try {
            $this->callbackService->handle([
                'payload'       => $request->all(),
                'signature'     => (string) $signature,
                'provider'      => $provider,
                'correlation_id' => $correlationId,
            ]);

            return response()->json(['acknowledged' => true]);
        } catch (DuplicateCallbackException) {
            return response()->json(['acknowledged' => true]); // idempotent 200
        }
    }
}
