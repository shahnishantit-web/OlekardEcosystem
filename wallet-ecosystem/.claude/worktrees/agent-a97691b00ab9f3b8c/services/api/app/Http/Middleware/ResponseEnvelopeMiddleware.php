<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class ResponseEnvelopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! ($response instanceof JsonResponse)) {
            return $response;
        }
        $body = $response->getData(true);
        if (array_key_exists('success', $body)) {
            return $response;
        }
        $envelope = [
            'success'        => $response->getStatusCode() < 400,
            'data'           => $body ?: null,
            'error'          => null,
            'correlation_id' => $request->header('X-Correlation-ID', ''),
            'meta'           => null,
        ];
        $response->setData($envelope);
        return $response;
    }
}
