<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class WebhookReceiveTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ── POST /v1/webhooks/{providerSlug} ─────────────────────────────────────

    public function test_valid_webhook_returns_200_and_stores_event(): void
    {
        $this->ensureIntegration(['provider_slug' => 'mock-pay', 'provider_type' => 'payment']);

        $response = $this->postSignedWebhook('/v1/webhooks/mock-pay', ['event' => 'payment.received', 'amount' => 100]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['webhook_event_id', 'status']]);

        $this->assertSame('received', $response->json('data.status'));

        Queue::assertPushed(ProcessWebhookEventJob::class, function ($job) use ($response) {
            return $job->webhookEventId === $response->json('data.webhook_event_id');
        });
    }

    public function test_unknown_provider_slug_returns_404(): void
    {
        $response = $this->postSignedWebhook('/v1/webhooks/unknown-provider-xyz', ['event' => 'test']);

        $response->assertStatus(404);
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    public function test_duplicate_delivery_returns_200_without_second_db_row(): void
    {
        $this->ensureIntegration(['provider_slug' => 'mock-sms-dedup', 'provider_type' => 'sms']);

        $payload = ['event' => 'sms.delivered', 'ref' => 'unique-ref-'.uniqid()];

        // First delivery.
        $first = $this->postSignedWebhook('/v1/webhooks/mock-sms-dedup', $payload);
        $first->assertStatus(200);
        $firstEventId = $first->json('data.webhook_event_id');

        // Second delivery — same payload.
        $second = $this->postSignedWebhook('/v1/webhooks/mock-sms-dedup', $payload);
        $second->assertStatus(200);
        $secondEventId = $second->json('data.webhook_event_id');

        // Both responses return the same event ID — no duplicate row.
        $this->assertSame($firstEventId, $secondEventId, 'Duplicate delivery must return the same event ID');

        $count = DB::connection('platform')
            ->table('integration.webhook_events')
            ->where('id', $firstEventId)
            ->count();

        $this->assertEquals(1, $count, 'Only one row must exist for the idempotency key');

        // ProcessWebhookEventJob dispatched once only.
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }
}
