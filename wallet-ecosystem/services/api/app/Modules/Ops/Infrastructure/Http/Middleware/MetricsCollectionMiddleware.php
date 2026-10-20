<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Http\Middleware;

use App\Modules\Ops\Application\Services\MetricsEmitter;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetricsCollectionMiddleware
{
    public function __construct(
        private readonly MetricsEmitter $emitter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startMs = hrtime(true) / 1_000_000;

        /** @var Response $response */
        $response = $next($request);

        $latencyMs = (hrtime(true) / 1_000_000) - $startMs;
        $isError   = $response->getStatusCode() >= 500;
        $now       = Carbon::now();

        $this->emitter->emit('api', 'http.request_count',  1,           'count', $now);
        $this->emitter->emit('api', 'http.latency_ms',     $latencyMs,  'ms',    $now);
        $this->emitter->emit('api', 'http.error_count',    $isError ? 1 : 0, 'count', $now);

        return $response;
    }
}
