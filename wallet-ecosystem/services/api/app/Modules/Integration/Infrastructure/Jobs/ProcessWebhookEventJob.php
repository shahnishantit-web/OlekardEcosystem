<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Jobs;

use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use App\Modules\Integration\Domain\Events\WebhookEventDeadLetteredEvent;
use App\Modules\Integration\Domain\Events\WebhookEventProcessedEvent;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Integration\Infrastructure\Models\WebhookProcessingAttemptModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

final class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retry logic is managed manually via RetryWebhookEventJob.

    public function __construct(
        public readonly string $webhookEventId,
        public readonly ?int $generation = null,
        public readonly ?int $attemptNumber = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(IntegrationRegistryInterface $registry, OutboxEventPublisher $outbox, MetricsCollectorInterface $metrics): void
    {
        $db = DB::connection('platform');
        $key = 'integration-webhook:'.$this->webhookEventId;
        $db->select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$key]);
        try {
            $event = WebhookEventModel::find($this->webhookEventId);

            if ($event === null) {
                return;
            }

            if ($event->status === WebhookEventStatus::Processed || $event->status === WebhookEventStatus::DeadLetter || $event->status === WebhookEventStatus::Failed) {
                return;
            }

            if (($this->generation !== null && $event->processing_generation !== $this->generation) || ($this->attemptNumber !== null && $event->retry_count !== $this->attemptNumber)) {
                return;
            }

            // Mark as processing.
            DB::connection('platform')->transaction(function () use ($event): void {
                $event->status = WebhookEventStatus::Processing;
                $event->save();
            });

            $correlationId = (string) Uuid::uuid7();

            try {
                $quarantineReason = $this->dispatchToDomainHandler($event, $registry);

                $event->refresh();
                if ($quarantineReason !== null) {
                    DB::connection('platform')->transaction(function () use ($event, $outbox, $correlationId, $quarantineReason): void {
                        $event->status = WebhookEventStatus::DeadLetter;
                        $event->last_error = 'quarantine:'.$quarantineReason;
                        $event->save();
                        $this->appendAttempt($event->id, $event->retry_count, 'failure', $event->last_error);
                        $outbox->publish(new WebhookEventDeadLetteredEvent($event->id, $event->provider_slug, $event->event_type, $event->retry_count, $correlationId));
                    });
                    return; // Domain validation quarantined evidence without changing money.
                }

                DB::connection('platform')->transaction(function () use ($event, $outbox, $correlationId): void {
                    $event->status = WebhookEventStatus::Processed;
                    $event->processed_at = CarbonImmutable::now();
                    $event->save();

                    $this->appendAttempt($event->id, $event->retry_count, 'success', null);

                    $outbox->publish(new WebhookEventProcessedEvent(
                        $event->id,
                        $event->provider_slug,
                        $event->event_type,
                        $correlationId,
                    ));
                });

                $metrics->increment('integration.webhook.processed', [
                    'provider_slug' => $event->provider_slug,
                    'event_type' => $event->event_type,
                ]);

            } catch (Throwable $e) {
                DB::connection('platform')->transaction(function () use ($event, $e, $outbox, $correlationId, $metrics): void {
                    $event->refresh();
                    $event->retry_count++;
                    $safeError = $event->verified_notification !== null ? 'payment_delivery_failed' : substr($e->getMessage(), 0, 500);
                    $event->last_error = $safeError;

                    if ($event->retry_count < $event->max_retry_count) {
                        $event->status = WebhookEventStatus::Failed;
                        $event->save();

                        $this->appendAttempt($event->id, $event->retry_count, 'failure', $safeError);
                    } else {
                        $event->status = WebhookEventStatus::DeadLetter;
                        $event->save();

                        $this->appendAttempt($event->id, $event->retry_count, 'failure', $safeError);

                        $outbox->publish(new WebhookEventDeadLetteredEvent(
                            $event->id,
                            $event->provider_slug,
                            $event->event_type,
                            $event->retry_count,
                            $correlationId,
                        ));

                        $metrics->increment('integration.webhook.dead_lettered', [
                            'provider_slug' => $event->provider_slug,
                            'event_type' => $event->event_type,
                        ]);
                    }
                });

                // Dispatch retry outside the transaction so the job is enqueued after commit.
                $event->refresh();
                if ($event->status === WebhookEventStatus::Failed) {
                    RetryWebhookEventJob::dispatch($this->webhookEventId, $event->retry_count, $event->processing_generation)
                        ->onQueue('webhooks');
                }
            }
        } finally {
            $db->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }

    private function dispatchToDomainHandler(WebhookEventModel $event, IntegrationRegistryInterface $registry): ?string
    {
        // Domain handler lookup: bound as "integration.webhook.handler.{provider_slug}.{event_type}".
        // If no handler is registered, processing silently succeeds (event is stored, no action taken).
        $abstract = "integration.webhook.handler.{$event->provider_slug}.{$event->event_type}";

        if ($event->verified_notification !== null && $event->platform_entity_type === 'payment_attempt') {
            $abstract = 'integration.webhook.handler.payment_attempt';
        }

        if (! app()->bound($abstract)) {
            if ($event->verified_notification !== null) {
                throw new \RuntimeException('Verified webhook handler is unavailable.');
            }
            return null;
        }

        $handler = app()->make($abstract);
        $result = $handler->handle($event);
        return $event->verified_notification !== null && is_string($result) && preg_match('/^[a-z_]+$/D', $result) ? $result : null;
    }

    private function appendAttempt(string $webhookEventId, int $attemptNumber, string $outcome, ?string $errorMessage): void
    {
        $attempt = new WebhookProcessingAttemptModel;
        $attempt->id = (string) Uuid::uuid7();
        $attempt->webhook_event_id = $webhookEventId;
        $attempt->attempt_number = $attemptNumber;
        $attempt->outcome = $outcome;
        $attempt->error_message = $errorMessage ? substr($errorMessage, 0, 500) : null;
        $attempt->attempted_at = CarbonImmutable::now();
        $attempt->save();
    }
}
