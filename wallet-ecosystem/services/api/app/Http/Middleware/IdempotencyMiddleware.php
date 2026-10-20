<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Platform\Application\Services\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class IdempotencyMiddleware
{
    private const IDEMPOTENT_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(private readonly IdempotencyService $idempotencyService) {}

    /**
     * @param string $mode  Pass 'require' (via route alias `idempotency:require`) to enforce
     *                      the Idempotency-Key header on financial mutation endpoints (FR-001).
     */
    public function handle(Request $request, Closure $next, string $mode = ''): SymfonyResponse
    {
        if (! in_array($request->method(), self::IDEMPOTENT_METHODS, true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey === null) {
            if ($mode === 'require') {
                return response()->json([
                    'success'        => false,
                    'data'           => null,
                    'error'          => ['code' => 'IDEMPOTENCY_KEY_REQUIRED', 'message' => 'Idempotency-Key header is required for this operation.'],
                    'correlation_id' => $request->header('X-Correlation-ID', ''),
                    'meta'           => null,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $next($request);
        }

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idempotencyKey)) {
            return response()->json([
                'success'        => false,
                'data'           => null,
                'error'          => ['code' => 'INVALID_IDEMPOTENCY_KEY', 'message' => 'Idempotency-Key must be a valid UUID.'],
                'correlation_id' => $request->header('X-Correlation-ID', ''),
                'meta'           => null,
            ], Response::HTTP_BAD_REQUEST);
        }

        $actorId      = $request->header('X-Actor-ID', '00000000-0000-0000-0000-000000000000');
        $requestHash  = hash('sha256', $request->method().':'.$request->path().':'.$request->getContent());
        $correlationId = $request->header('X-Correlation-ID', '');

        try {
            $cached = $this->idempotencyService->resolve(
                idempotencyKey: $idempotencyKey,
                actorId: $actorId,
                httpMethod: $request->method(),
                httpPath: $request->path(),
                requestHash: $requestHash,
                correlationId: $correlationId,
            );
        } catch (IdempotencyKeyMismatchException | IdempotencyInProgressException $e) {
            throw $e;
        }

        if ($cached !== null) {
            return response()->json($cached['body'], Response::HTTP_OK)
                ->header('Idempotency-Replay', 'true');
        }

        $response = $next($request);

        // Record the response for future replays
        if ($response->getStatusCode() < 500) {
            $body = json_decode($response->getContent(), true) ?? [];
            $this->idempotencyService->complete(
                idempotencyKey: $idempotencyKey,
                actorId: $actorId,
                responseStatus: $response->getStatusCode(),
                responseBody: $body,
            );
        }

        return $response;
    }
}
