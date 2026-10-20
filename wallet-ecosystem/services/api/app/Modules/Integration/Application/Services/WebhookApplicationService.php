<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Services;

use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Domain\Enums\WebhookEventStatus;
use App\Modules\Integration\Domain\Events\WebhookEventReceivedEvent;
use App\Modules\Integration\Domain\Exceptions\WebhookNotDeadLetterException;
use App\Modules\Integration\Domain\Exceptions\WebhookProviderNotFoundException;
use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WebhookApplicationService
{
    public function __construct(
        private readonly WebhookEventRepositoryInterface $repository,
        private readonly IntegrationRegistryInterface $registry,
        private readonly OutboxEventPublisher $outbox,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function receive(
        string $providerSlug,
        string $rawPayload,
        string $signature,
        array $headers,
        string $sourceIp,
    ): WebhookEventModel {
        $integration = $this->registry->resolveIntegrationBySlug($providerSlug);

        if ($integration === null) {
            throw WebhookProviderNotFoundException::forSlug($providerSlug);
        }

        $webhookContract = $this->registry->resolveWebhookContract($providerSlug);

        if ($webhookContract === null) {
            abort(401, 'Webhook signature verification is not configured.');
        } else {
            // Throws WebhookSignatureException on invalid signature.
            $normalized = $webhookContract->parse($rawPayload, $signature, $headers);

            $idempotencyKey = $normalized->idempotencyKey;
            $eventType = $normalized->normalizedEventType;
            $platformEntityId = $normalized->platformEntityId;
            $platformEntityType = $normalized->platformEntityType;
        }

        // Check idempotency — return existing event if already received.
        $existing = $this->repository->findByIdempotencyKey($providerSlug, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $correlationId = request()->header('X-Correlation-ID') ?? (string) Uuid::uuid7();

        $created = false;
        $event = DB::connection('platform')->transaction(function () use (
            &$created,
            $integration, $providerSlug, $idempotencyKey, $eventType,
            $rawPayload, $sourceIp, $platformEntityId, $platformEntityType, $correlationId,
        ): WebhookEventModel {
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['integration-receive:'.$providerSlug.':'.$idempotencyKey]);
            $existing = $this->repository->findByIdempotencyKey($providerSlug, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $created = true;
            $model = new WebhookEventModel;
            $model->id = (string) Uuid::uuid7();
            $model->provider_type = $integration->provider_type->value;
            $model->provider_slug = $providerSlug;
            $model->idempotency_key = $idempotencyKey;
            $model->event_type = $eventType;
            $model->raw_payload = $rawPayload;
            $model->source_ip_hash = hash('sha256', $sourceIp);
            $model->status = WebhookEventStatus::Received;
            $model->retry_count = 0;
            $model->max_retry_count = 5;
            $model->platform_entity_id = $platformEntityId;
            $model->platform_entity_type = $platformEntityType;
            $model->received_at = CarbonImmutable::now();

            $this->repository->save($model);

            $this->outbox->publish(new WebhookEventReceivedEvent(
                $model->id,
                $providerSlug,
                $eventType,
                $correlationId,
            ));

            return $model;
        });

        // Dispatch processing AFTER DB commit so job sees persisted row.
        if ($created) {
            ProcessWebhookEventJob::dispatch($event->id, $event->processing_generation, $event->retry_count)->onQueue('webhooks')->afterCommit();
        }

        $this->metrics->increment('integration.webhook.received', ['provider_slug' => $providerSlug]);

        return $event;
    }

    public function requeue(string $webhookEventId): WebhookEventModel
    {
        $event = $this->repository->findById($webhookEventId);
        if ($event === null) {
            throw new \RuntimeException('Webhook event not found.');
        }
        if ($event->status !== WebhookEventStatus::DeadLetter) {
            throw WebhookNotDeadLetterException::forEvent($webhookEventId, $event->status->value);
        }
        app(AdminIntegrationService::class)->retry($webhookEventId, (string) request()->attributes->get('identity.user_id'), request()->header('X-Correlation-ID') ?: (string) Uuid::uuid7());

        return $event->refresh();
    }
}
