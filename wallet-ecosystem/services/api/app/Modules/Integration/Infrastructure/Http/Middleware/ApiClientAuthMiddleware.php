<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Middleware;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * M2M API client authentication via Bearer base64(api_key:secret).
 *
 * On success: injects integration.api_client_id, integration.rate_limit_per_minute,
 * and integration.permissions into request attributes and touches last_used_at.
 */
final class ApiClientAuthMiddleware
{
    public function __construct(private readonly MetricsCollectorInterface $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startMs = microtime(true) * 1000.0;

        $bearer = $this->extractBearer($request);

        if ($bearer === null) {
            $this->metrics->increment('integration.request.auth_failure', ['reason' => 'missing_header']);

            return $this->unauthorized('Missing or malformed Authorization header.');
        }

        $decoded = base64_decode($bearer, strict: true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            $this->metrics->increment('integration.request.auth_failure', ['reason' => 'malformed_token']);

            return $this->unauthorized('Malformed bearer token.');
        }

        $colonPos = (int) strpos($decoded, ':'); // str_contains(':') check above guarantees this is non-false
        $apiKey = substr($decoded, 0, $colonPos);
        $rawSecret = substr($decoded, $colonPos + 1);

        $client = ApiClientModel::where('api_key', $apiKey)->first();

        if ($client === null) {
            $this->metrics->increment('integration.request.auth_failure', ['reason' => 'invalid_credentials']);

            return $this->unauthorized('Invalid credentials.');
        }

        if ($client->status !== ApiClientStatus::Active) {
            $this->metrics->increment('integration.request.auth_failure', ['reason' => 'inactive_client']);

            return $this->unauthorized('API client is inactive.');
        }

        // Constant-time bcrypt verification — prevents timing attacks.
        if (! password_verify($rawSecret, $client->secret_hash)) {
            $this->metrics->increment('integration.request.auth_failure', ['reason' => 'invalid_credentials']);

            return $this->unauthorized('Invalid credentials.');
        }

        if ($client->revoked_at !== null || (! empty($client->ip_whitelist) && ! IpUtils::checkIp($request->ip(), $client->ip_whitelist))) {
            return $this->unauthorized('Invalid credentials or source address.');
        }

        // Inject client context for downstream middleware and handlers.
        $request->attributes->set('integration.api_client_id', $client->id);
        $request->attributes->set('integration.rate_limit_per_minute', $client->rate_limit_per_minute);
        $request->attributes->set('integration.permissions', $client->permissions ?? []);

        // Update last_used_at non-blocking.
        DB::connection('platform')
            ->table('integration.api_clients')
            ->where('id', $client->id)
            ->update(['last_used_at' => now()]);

        $this->metrics->histogram('integration.request.latency_ms', round(microtime(true) * 1000.0 - $startMs, 2));

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'INTEGRATION_AUTH_001',
                'message' => $message,
            ],
        ], 401);
    }
}
