<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Infrastructure\Metrics\InMemoryCollector;
use Illuminate\Support\Facades\Redis;

final class RateLimitTest extends IntegrationTestCase
{
    private InMemoryCollector $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Route::get('/_test/e25-client', fn () => response()->json(['ok' => true]))
            ->middleware([
                \App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware::class,
                \App\Modules\Integration\Infrastructure\Http\Middleware\DualRateLimitMiddleware::class,
            ]);

        // Reset metrics collector so counts are per-test.
        /** @var InMemoryCollector $collector */
        $collector = $this->app->make(MetricsCollectorInterface::class);
        $collector->flush();
        $this->metrics = $collector;
    }

    // ── DualRateLimitMiddleware ───────────────────────────────────────────────

    public function test_requests_within_limit_are_allowed(): void
    {
        $client = $this->ensureApiClient(['rate_limit_per_minute' => 5]);

        // Make 5 requests — all should pass.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders([
                'Authorization' => $this->bearerHeader($client['api_key'], $client['raw_secret']),
            ])->getJson('/_test/e25-client');

            $response->assertStatus(200);
        }
    }

    public function test_request_exceeding_per_client_limit_returns_429(): void
    {
        $client = $this->ensureApiClient(['rate_limit_per_minute' => 2]);
        $headers = ['Authorization' => $this->bearerHeader($client['api_key'], $client['raw_secret'])];

        // First two succeed.
        $this->withHeaders($headers)->getJson('/_test/e25-client')->assertStatus(200);
        $this->withHeaders($headers)->getJson('/_test/e25-client')->assertStatus(200);

        // Third exceeds the limit.
        $response = $this->withHeaders($headers)->getJson('/_test/e25-client');
        $response->assertStatus(429)
            ->assertJsonStructure(['error' => ['message']])
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining')
            ->assertHeader('Retry-After');

        $this->assertEquals('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_rate_limit_headers_are_present_on_successful_request(): void
    {
        $client = $this->ensureApiClient(['rate_limit_per_minute' => 60]);

        $response = $this->withHeaders([
            'Authorization' => $this->bearerHeader($client['api_key'], $client['raw_secret']),
        ])->getJson('/_test/e25-client');

        $response->assertStatus(200)
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');
    }

    // ── ApiClientAuthMiddleware ───────────────────────────────────────────────

    public function test_missing_authorization_header_returns_401(): void
    {
        $response = $this->getJson('/_test/e25-client');
        // Without identity.auth or client-auth on this endpoint, expect 401.
        $response->assertStatus(401);
    }

    public function test_malformed_bearer_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer not-valid-base64!!!',
        ])->getJson('/_test/e25-client');

        $response->assertStatus(401);
    }

    public function test_wrong_secret_returns_401(): void
    {
        $client = $this->ensureApiClient();

        $response = $this->withHeaders([
            'Authorization' => $this->bearerHeader($client['api_key'], 'wrong-secret'),
        ])->getJson('/_test/e25-client');

        $response->assertStatus(401);
    }

    public function test_inactive_client_returns_401(): void
    {
        $client = $this->ensureApiClient(['status' => 'inactive']);

        $response = $this->withHeaders([
            'Authorization' => $this->bearerHeader($client['api_key'], $client['raw_secret']),
        ])->getJson('/_test/e25-client');

        $response->assertStatus(401);
    }

    // ── Metric emission ───────────────────────────────────────────────────────

    public function test_rate_limit_exceeded_emits_rate_limited_metric(): void
    {
        // Register a provider so the webhook receiver doesn't 404.
        $this->ensureIntegration(['provider_slug' => 'metric-test-provider']);

        // POST two identical requests to the webhook receiver — IP window is 300/min
        // which won't trip, but we can lower it via config for this test.
        // Instead, simulate the client window by injecting a pre-seeded client
        // and routing through the public webhook endpoint which carries both middleware.
        // We configure a rate limit of 1 and send 2 requests.
        config(['integration.ip_rate_limit_per_minute' => 1]);

        $this->postJson('/v1/webhooks/metric-test-provider', ['event' => 'test']);
        $this->postJson('/v1/webhooks/metric-test-provider', ['event' => 'test']);

        // At least one 429 should have been emitted (IP window exceeded on 2nd request).
        $this->assertGreaterThanOrEqual(
            1,
            $this->metrics->getCount('integration.request.rate_limited', ['window' => 'ip']),
        );
    }

    public function test_successful_auth_emits_latency_histogram(): void
    {
        $client = $this->ensureApiClient();

        $middleware = $this->app->make(\App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware::class);
        $request    = \Illuminate\Http\Request::create('/test', 'GET');
        $request->headers->set('Authorization', $this->bearerHeader($client['api_key'], $client['raw_secret']));

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(
            1,
            count($this->metrics->getHistogramValues('integration.request.latency_ms')),
            'Successful auth must emit a latency histogram sample',
        );
    }

    public function test_auth_failure_emits_auth_failure_metric(): void
    {
        $this->postJson('/v1/webhooks/nonexistent-provider', [])
            ->assertStatus(404); // 404 from WebhookProviderNotFoundException — not an auth failure

        // Wrong secret on a known provider triggers ApiClientAuthMiddleware only if
        // the webhook endpoint uses integration.client-auth. Since it does not (it uses
        // signature-level auth inside the controller), we test auth failure via a direct
        // M2M endpoint. We seed an inactive client and call a route that uses client-auth.
        // For the purposes of this assertion test, trigger the middleware directly.
        $client = $this->ensureApiClient(['status' => ApiClientStatus::Inactive->value]);

        // Any route that uses integration.client-auth would work; simulate by resolving
        // the middleware directly through the container so we don't depend on route config.
        $middleware = $this->app->make(\App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware::class);
        $request    = \Illuminate\Http\Request::create('/test', 'GET');
        $request->headers->set('Authorization', $this->bearerHeader($client['api_key'], $client['raw_secret']));

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(
            1,
            $this->metrics->getCount('integration.request.auth_failure', ['reason' => 'inactive_client']),
        );
    }
}
