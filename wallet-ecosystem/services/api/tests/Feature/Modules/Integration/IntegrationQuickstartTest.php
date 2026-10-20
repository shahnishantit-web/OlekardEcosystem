<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware;
use App\Modules\Integration\Infrastructure\Http\Middleware\DualRateLimitMiddleware;
use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Ramsey\Uuid\Uuid;

/**
 * @group docker
 *
 * E25 API & Integration Platform — Quickstart SC-1 through SC-10.
 * Validates the acceptance scenarios from specs/025-api-integration-platform/quickstart.md.
 */
final class IntegrationQuickstartTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        Queue::fake();
    }

    // ── SC-1: Create and authenticate an API client ──────────────────────────

    /** SC-1a: POST /api/v1/integrations/clients returns 201 with api_key and raw_secret. */
    public function test_sc1_create_client_returns_201_with_credentials(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');

        $response = $this->withActorToken($admin)->postJson('/v1/integrations/clients', [
            'name'                  => 'SC1 Test Client',
            'permissions'           => ['funding.initiate'],
            'rate_limit_per_minute' => 30,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['data' => ['id', 'name', 'api_key', 'raw_secret', 'status']]);
        $this->assertNotEmpty($response->json('data.api_key'));
        $this->assertNotEmpty($response->json('data.raw_secret'));
        $this->assertSame('active', $response->json('data.status'));
    }

    /** SC-1b: API client bearer credentials authenticate successfully via ApiClientAuthMiddleware. */
    public function test_sc1_client_credentials_authenticate_successfully(): void
    {
        $client     = $this->ensureApiClient();
        $middleware = $this->app->make(ApiClientAuthMiddleware::class);

        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', $this->bearerHeader($client['api_key'], $client['raw_secret']));

        $capturedId = null;
        $response = $middleware->handle($request, function (Request $r) use (&$capturedId) {
            $capturedId = $r->attributes->get('integration.api_client_id');
            return response()->json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($client['id'], $capturedId);
    }

    // ── SC-2: Per-client rate limiting ───────────────────────────────────────

    /** SC-2: DualRateLimitMiddleware enforces the per-client rate limit. */
    public function test_sc2_per_client_rate_limit_blocks_excess_requests(): void
    {
        $client     = $this->ensureApiClient(['rate_limit_per_minute' => 2]);
        $middleware = $this->app->make(DualRateLimitMiddleware::class);

        $fire = function () use ($client, $middleware): int {
            $request = Request::create('/test', 'GET');
            $request->attributes->set('integration.api_client_id', $client['id']);
            $request->attributes->set('integration.rate_limit_per_minute', 2);
            return $middleware->handle($request, fn ($r) => response()->json(['ok' => true]))->getStatusCode();
        };

        // First two requests within the per-client limit succeed.
        $this->assertSame(200, $fire());
        $this->assertSame(200, $fire());

        // Third request exceeds the per-client window (limit=2).
        $this->assertSame(429, $fire());
    }

    // ── SC-3: Per-IP rate limiting ───────────────────────────────────────────

    /** SC-3: IP-level rate limit fires on the webhook endpoint when the window is exceeded. */
    public function test_sc3_ip_rate_limit_blocks_excess_webhook_requests(): void
    {
        config(['integration.ip_rate_limit_per_minute' => 1]);
        $slug = 'sc3-ip-' . substr((string) Uuid::uuid7(), 0, 8);
        $this->ensureIntegration(['provider_slug' => $slug]);

        // First request is within the IP limit (count=1 ≤ limit=1).
        $this->postJson("/v1/webhooks/{$slug}", ['event' => 'test'])->assertStatus(200);

        // Second request exceeds the IP window (count=2 > limit=1).
        $blocked = $this->postJson("/v1/webhooks/{$slug}", ['event' => 'test']);
        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
    }

    // ── SC-4: Secret rotation ────────────────────────────────────────────────

    /** SC-4: Rotating the secret invalidates the old credentials immediately. */
    public function test_sc4_rotate_secret_invalidates_old_credentials(): void
    {
        $admin      = $this->makeAdminWithPermission('integrations.manage');
        $client     = $this->ensureApiClient();
        $middleware = $this->app->make(ApiClientAuthMiddleware::class);

        $makeAuthRequest = fn (string $apiKey, string $rawSecret): int => $middleware->handle(
            tap(Request::create('/test', 'GET'), fn ($r) => $r->headers->set(
                'Authorization',
                $this->bearerHeader($apiKey, $rawSecret),
            )),
            fn ($r) => response()->json(['ok' => true]),
        )->getStatusCode();

        // Old credentials work before rotation.
        $this->assertSame(200, $makeAuthRequest($client['api_key'], $client['raw_secret']));

        // Rotate the secret.
        $rotateResponse = $this->withActorToken($admin)
            ->postJson("/v1/integrations/clients/{$client['id']}/rotate-secret");
        $rotateResponse->assertStatus(200);
        $this->assertNotEmpty($rotateResponse->json('data.raw_secret'));

        // Old credentials must be rejected after rotation.
        $this->assertSame(401, $makeAuthRequest($client['api_key'], $client['raw_secret']));
    }

    // ── SC-5: Receive a valid provider webhook ───────────────────────────────

    /** SC-5: Provider webhook without a registered contract returns 200 with status=received. */
    public function test_sc5_valid_webhook_returns_200_with_received_status(): void
    {
        $slug = 'sc5-pay-' . substr((string) Uuid::uuid7(), 0, 8);
        $this->ensureIntegration(['provider_slug' => $slug]);

        $response = $this->postJson("/v1/webhooks/{$slug}", [
            'event'     => 'payment.success',
            'reference' => 'TXN-SC5-001',
            'amount'    => 5000,
        ]);

        $response->assertStatus(200);
        $this->assertSame('received', $response->json('data.status'));
        $this->assertNotEmpty($response->json('data.webhook_event_id'));
    }

    // ── SC-6: Reject tampered webhook ────────────────────────────────────────

    /** SC-6: Invalid HMAC signature returns 401 with error.code = INTEGRATION_WEBHOOK_001. */
    public function test_sc6_tampered_webhook_returns_401_with_integration_webhook_001(): void
    {
        $slug   = 'sc6-pay-' . substr((string) Uuid::uuid7(), 0, 8);
        $secret = 'test-webhook-secret-sc6';
        $this->ensureIntegration(['provider_slug' => $slug]);

        // Bind a strict webhook contract that validates HMAC-SHA256 against $secret.
        $this->app->bind("integration.webhook.{$slug}", function () use ($secret): ProviderWebhookContract {
            return new class ($secret) implements ProviderWebhookContract {
                public function __construct(private readonly string $secret) {}

                public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent
                {
                    $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $this->secret);
                    if (!hash_equals($expected, $signature)) {
                        throw new WebhookSignatureException('Invalid signature.');
                    }
                    // Never reached in this test — only invalid signatures are sent.
                    throw new WebhookSignatureException('Unexpected code path in SC-6.');
                }
            };
        });

        $response = $this->postJson("/v1/webhooks/{$slug}", [
            'event'     => 'payment.success',
            'reference' => 'TXN-001',
            'amount'    => 5000,
        ], ['X-Signature' => 'sha256=invalidsignature']);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INTEGRATION_WEBHOOK_001');
    }

    // ── SC-7: Webhook idempotency (duplicate delivery) ───────────────────────

    /** SC-7: Duplicate webhook delivery returns the same event_id; only one DB row is created. */
    public function test_sc7_duplicate_webhook_delivery_is_idempotent(): void
    {
        $slug    = 'sc7-sms-' . substr((string) Uuid::uuid7(), 0, 8);
        $payload = ['event' => 'sms.delivered', 'ref' => 'SC7-REF-001'];
        $this->ensureIntegration(['provider_slug' => $slug]);

        $first = $this->postJson("/v1/webhooks/{$slug}", $payload);
        $first->assertStatus(200);
        $firstId = $first->json('data.webhook_event_id');

        // Duplicate delivery with the same payload.
        $second = $this->postJson("/v1/webhooks/{$slug}", $payload);
        $second->assertStatus(200);
        $secondId = $second->json('data.webhook_event_id');

        $this->assertSame($firstId, $secondId, 'Duplicate delivery must return the same event ID');

        $rowCount = DB::connection('platform')
            ->table('integration.webhook_events')
            ->where('id', $firstId)
            ->count();
        $this->assertEquals(1, $rowCount, 'Only one DB row must exist for the idempotency key');
    }

    // ── SC-8: Webhook handler failure → dead_letter ──────────────────────────

    /** SC-8: Handler failure at max_retry_count transitions the event to dead_letter. */
    public function test_sc8_handler_failure_at_max_retries_creates_dead_letter(): void
    {
        $slug  = 'sc8-pay-' . substr((string) Uuid::uuid7(), 0, 8);
        $event = $this->seedWebhookEvent([
            'provider_slug'   => $slug,
            'event_type'      => 'payment.failed',
            'retry_count'     => 2,
            'max_retry_count' => 3,
        ]);

        // Bind a handler that always fails.
        $this->app->bind("integration.webhook.handler.{$slug}.payment.failed", function () {
            return new class {
                public function handle(WebhookEventModel $e): void
                {
                    throw new \RuntimeException('Simulated SC-8 handler failure.');
                }
            };
        });

        // Run the job directly (not via queue — retry_count 2+1=3 = max → dead_letter).
        $this->app->call([new ProcessWebhookEventJob($event->id), 'handle']);

        $event->refresh();
        $this->assertSame(WebhookEventStatus::DeadLetter, $event->status);
    }

    // ── SC-9: Register and list integrations ─────────────────────────────────

    /** SC-9: New integration registered via POST appears in the GET list filtered by type. */
    public function test_sc9_register_integration_appears_in_type_filtered_list(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $slug  = 'sc9-sms-' . substr((string) Uuid::uuid7(), 0, 8);

        $createResponse = $this->withActorToken($admin)->postJson('/v1/integrations/providers', [
            'provider_type'         => 'sms',
            'provider_slug'         => $slug,
            'display_name'          => 'SC9 SMS Provider',
            'credentials_reference' => 'platform.integrations.sc9_sms',
            'is_default'            => false,
        ]);
        $createResponse->assertStatus(201);
        $this->assertNotEmpty($createResponse->json('data.id'));

        $listResponse = $this->withActorToken($admin)->getJson('/v1/integrations/providers?provider_type=sms');
        $listResponse->assertStatus(200);

        $slugs = collect($listResponse->json('data'))->pluck('provider_slug')->toArray();
        $this->assertContains($slug, $slugs, 'Newly registered SMS integration must appear in the type-filtered list');
    }

    // ── SC-10: Re-queue a dead-letter webhook event ───────────────────────────

    /** SC-10: Requeueing a dead-letter event resets status to received with retry_count=0. */
    public function test_sc10_requeue_dead_letter_resets_status_to_received(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $event = $this->seedWebhookEvent([
            'status'      => WebhookEventStatus::DeadLetter->value,
            'retry_count' => 5,
        ]);

        $response = $this->withActorToken($admin)
            ->postJson("/v1/integrations/webhooks/{$event->id}/requeue");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'received');
        $response->assertJsonPath('data.retry_count', 0);

        Queue::assertPushed(ProcessWebhookEventJob::class, function ($job) use ($event) {
            return $job->webhookEventId === $event->id;
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedWebhookEvent(array $overrides = []): WebhookEventModel
    {
        $id = (string) Uuid::uuid7();

        $data = array_merge([
            'id'              => $id,
            'provider_type'   => 'payment',
            'provider_slug'   => 'qs-provider',
            'idempotency_key' => 'qs-' . uniqid(),
            'event_type'      => 'generic',
            'raw_payload'     => '{}',
            'status'          => WebhookEventStatus::Received->value,
            'retry_count'     => 0,
            'max_retry_count' => 3,
            'last_error'      => null,
            'received_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides);

        DB::connection('platform')->table('integration.webhook_events')->insert($data);

        return WebhookEventModel::find($id);
    }
}
