<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class AddCorrelationIdHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?? (string) Uuid::uuid4();

        $request->headers->set('X-Correlation-ID', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
