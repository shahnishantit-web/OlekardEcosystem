<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Middleware;

use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (empty($request->header('Idempotency-Key'))) {
            $correlationId = $request->header('X-Correlation-ID') ?? Uuid::uuid4()->toString();

            return ErrorResource::make(
                'ADMINAPP_IDEM_001',
                'The Idempotency-Key header is required for this request.',
                $correlationId,
                422,
            );
        }

        return $next($request);
    }
}
