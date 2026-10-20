<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Jobs\PaymentCallbackProcessingJob;
use App\Modules\Payment\Infrastructure\Models\PaymentCallbackModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * T128: Receives raw provider webhook callbacks.
 *
 * Obligations:
 * 1. Capture raw body bytes BEFORE any JSON parsing.
 * 2. Persist raw body + headers + provider_code in a single DB transaction.
 * 3. Dispatch PaymentCallbackProcessingJob after persist.
 * 4. Return HTTP 202 regardless of queue availability.
 * 5. Return HTTP 400 ONLY if body is empty.
 */
final class PaymentCallbackController extends Controller
{
    public function store(Request $request, string $providerCode): JsonResponse|Response
    {
        // Capture raw body before any framework JSON parsing
        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return response()->json(['message' => 'Empty callback body.'], 400);
        }

        $signature  = $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Mock-Bank-Signature')
            ?? '';

        /** @var array<string, string> $headers */
        $headers = collect($request->headers->all())
            ->map(fn(array $v): string => implode(',', $v))
            ->toArray();

        $callbackId = (string) Uuid::uuid7();

        // Persist raw callback before async processing
        DB::connection('payment')->transaction(static function () use (
            $callbackId, $providerCode, $rawBody, $headers, $signature
        ): void {
            $callback = new PaymentCallbackModel();
            $callback->id              = $callbackId;
            $callback->provider_code   = $providerCode;
            $callback->raw_body        = $rawBody;
            $callback->headers         = $headers;
            $callback->signature_header = $signature;
            $callback->status          = 'received';
            $callback->received_at     = now();
            $callback->created_at      = now();
            $callback->save();
        });

        // Dispatch async processing job
        PaymentCallbackProcessingJob::dispatch($callbackId);

        return response()->json(['message' => 'Accepted.'], 202);
    }
}
