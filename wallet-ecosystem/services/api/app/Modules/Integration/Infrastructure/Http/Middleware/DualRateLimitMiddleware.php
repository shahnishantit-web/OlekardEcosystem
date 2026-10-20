<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Middleware;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evaluates two independent Redis sorted-set sliding windows per request:
 *   1. Per API client: ratelimit:client:{api_client_id}
 *   2. Per source IP:  ratelimit:ip:{sha256(ip)}
 *
 * Either window exceeding its quota returns 429 with Retry-After header.
 * Window quotas are configurable; defaults: client=60/min, IP=300/min.
 */
final class DualRateLimitMiddleware
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly MetricsCollectorInterface $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $now        = (int) (microtime(true) * 1000);
        $windowMs   = self::WINDOW_SECONDS * 1000;
        $windowStart = $now - $windowMs;

        // ── Per-client window ────────────────────────────────────────────────
        $clientId = (string) $request->attributes->get('integration.api_client_id', '');

        if ($clientId !== '') {
            $clientLimit = (int) $request->attributes->get('integration.rate_limit_per_minute', 60);
            $clientKey   = "ratelimit:client:{$clientId}";
            $clientResult = $this->checkWindow($clientKey, $now, $windowStart, $clientLimit);

            if ($clientResult['exceeded']) {
                $this->metrics->increment('integration.request.rate_limited', ['window' => 'client']);
                return $this->tooManyResponse($clientResult['retry_after'], $clientLimit, $clientResult['remaining']);
            }
        }

        // ── Per-IP window ────────────────────────────────────────────────────
        $ipHash   = hash('sha256', $request->ip() ?? '');
        $ipLimit  = (int) config('integration.ip_rate_limit_per_minute', 300);
        $ipKey    = "ratelimit:ip:{$ipHash}";
        $ipResult = $this->checkWindow($ipKey, $now, $windowStart, $ipLimit);

        if ($ipResult['exceeded']) {
            $this->metrics->increment('integration.request.rate_limited', ['window' => 'ip']);
            return $this->tooManyResponse($ipResult['retry_after'], $ipLimit, $ipResult['remaining']);
        }

        return $next($request);
    }

    /** @return array{exceeded: bool, remaining: int, retry_after: int} */
    private function checkWindow(string $key, int $now, int $windowStart, int $limit): array
    {
        $redis = Redis::connection();

        // Lua script: atomic ZADD + ZREMRANGEBYSCORE + ZCOUNT + EXPIRE
        $luaScript = <<<'LUA'
            local key        = KEYS[1]
            local now        = tonumber(ARGV[1])
            local windowStart = tonumber(ARGV[2])
            local limit      = tonumber(ARGV[3])
            local ttl        = tonumber(ARGV[4])

            redis.call('ZREMRANGEBYSCORE', key, '-inf', windowStart)
            redis.call('ZADD', key, now, now .. '-' .. math.random(1, 999999))
            local count = redis.call('ZCOUNT', key, windowStart, '+inf')
            redis.call('EXPIRE', key, ttl)

            return count
        LUA;

        // @phpstan-ignore-next-line
        $count = (int) $redis->eval(
            $luaScript,
            1,
            $key,
            (string) $now,
            (string) $windowStart,
            (string) $limit,
            (string) (self::WINDOW_SECONDS + 1),
        );

        $exceeded  = $count > $limit;
        $remaining = max(0, $limit - $count);

        return [
            'exceeded'    => $exceeded,
            'remaining'   => $remaining,
            'retry_after' => self::WINDOW_SECONDS,
        ];
    }

    private function tooManyResponse(int $retryAfter, int $limit, int $remaining): Response
    {
        return response()->json([
            'success' => false,
            'error'   => [
                'code'    => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Rate limit exceeded. Please retry after the indicated period.',
            ],
        ], 429, [
            'Retry-After'          => (string) $retryAfter,
            'X-RateLimit-Limit'    => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
        ]);
    }
}
