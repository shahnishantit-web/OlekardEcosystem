<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use App\Modules\Integration\Infrastructure\Jobs\DeliverAdminRetryIntentsJob;
use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use App\Modules\Integration\Infrastructure\Jobs\RetryWebhookEventJob;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;

final class WebhookRetryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function seedWebhookEvent(array $overrides = []): WebhookEventModel
    {
        $id = (string) Uuid::uuid7();

        $data = array_merge([
            'id' => $id,
            'provider_type' => 'payment',
            'provider_slug' => 'mock-pay',
            'idempotency_key' => 'retry-'.uniqid(),
            'event_type' => 'payment.received',
            'raw_payload' => '{}',
            'status' => WebhookEventStatus::Received->value,
            'retry_count' => 0,
            'max_retry_count' => 3,
            'last_error' => null,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::connection('platform')->table('integration.webhook_events')->insert($data);

        return WebhookEventModel::find($id);
    }

    // ── Handler failure → failed → RetryWebhookEventJob dispatched ───────────

    public function test_handler_failure_sets_status_failed_and_dispatches_retry(): void
    {
        $event = $this->seedWebhookEvent();

        // Bind a handler that throws.
        $this->app->bind('integration.webhook.handler.mock-pay.payment.received', function () {
            return new class
            {
                public function handle(WebhookEventModel $event): void
                {
                    throw new \RuntimeException('Simulated handler failure');
                }
            };
        });

        $job = new ProcessWebhookEventJob($event->id);
        $this->app->call([$job, 'handle']);

        $event->refresh();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertEquals(1, $event->retry_count);

        Queue::assertPushed(RetryWebhookEventJob::class, function ($j) use ($event) {
            return $j->webhookEventId === $event->id;
        });
    }

    // ── Max retries reached → dead_letter ────────────────────────────────────

    public function test_max_retries_reached_sets_dead_letter(): void
    {
        $event = $this->seedWebhookEvent(['retry_count' => 2, 'max_retry_count' => 3]);

        $this->app->bind('integration.webhook.handler.mock-pay.payment.received', function () {
            return new class
            {
                public function handle(WebhookEventModel $e): void
                {
                    throw new \RuntimeException('Still failing');
                }
            };
        });

        $job = new ProcessWebhookEventJob($event->id);
        $this->app->call([$job, 'handle']);

        $event->refresh();
        $this->assertSame(WebhookEventStatus::DeadLetter, $event->status);

        // No further RetryWebhookEventJob dispatched — already dead-lettered.
        Queue::assertNotPushed(RetryWebhookEventJob::class);
    }

    // ── Requeue endpoint resets retry_count ──────────────────────────────────

    public function test_requeue_resets_retry_count_and_status(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $event = $this->seedWebhookEvent([
            'status' => WebhookEventStatus::DeadLetter->value,
            'retry_count' => 5,
        ]);

        $response = $this->withActorToken($admin)
            ->postJson("/v1/integrations/webhooks/{$event->id}/requeue");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.retry_count', 0);

        app(DeliverAdminRetryIntentsJob::class)->handle();
        Queue::assertPushed(ProcessWebhookEventJob::class, function ($j) use ($event) {
            return $j->webhookEventId === $event->id;
        });
    }

    // ── Requeue non-dead_letter returns 409 ──────────────────────────────────

    public function test_requeue_non_dead_letter_returns_409(): void
    {
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $event = $this->seedWebhookEvent(['status' => WebhookEventStatus::Failed->value]);

        $response = $this->withActorToken($admin)
            ->postJson("/v1/integrations/webhooks/{$event->id}/requeue");

        $response->assertStatus(409);
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }
}
