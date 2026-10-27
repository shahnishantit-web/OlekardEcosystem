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
        $paymentReceipt = isset($normalized->metadata['payment_account_id']);
        $existing = $paymentReceipt
            ? WebhookEventModel::whereNotNull('verified_notification')->where('idempotency_key', $idempotencyKey)->first()
            : $this->repository->findByIdempotencyKey($providerSlug, $idempotencyKey);

        if ($existing !== null) {
            $this->verifyReceiptIdentity($existing, $rawPayload);
            return $existing;
        }

        $correlationId = request()->header('X-Correlation-ID') ?? (string) Uuid::uuid7();

        $created = false;
        $event = DB::connection('platform')->transaction(function () use (
            &$created,
            $integration, $providerSlug, $idempotencyKey, $eventType,
            $rawPayload, $sourceIp, $platformEntityId, $platformEntityType, $correlationId, $normalized, $paymentReceipt,
        ): WebhookEventModel {
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['integration-receive:'.($paymentReceipt ? 'payment' : $providerSlug).':'.$idempotencyKey]);
            $existing = $paymentReceipt
                ? WebhookEventModel::whereNotNull('verified_notification')->where('idempotency_key', $idempotencyKey)->first()
                : $this->repository->findByIdempotencyKey($providerSlug, $idempotencyKey);
            if ($existing !== null) {
                $this->verifyReceiptIdentity($existing, $rawPayload);
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
            if (isset($normalized->metadata['payment_account_id'])) {
                $model->payload_hash = hash('sha256', $rawPayload);
                $model->encrypted_evidence = $rawPayload;
                $model->raw_payload = '[restricted payment evidence]';
                $model->verified_notification = [
                    'entity_id' => $platformEntityId,
                    'provider_reference' => $normalized->providerReference,
                    'status' => $normalized->status->value,
                    'correlation_id' => $correlationId,
                    'context' => $normalized->metadata,
                ];
            }
            $model->source_ip_hash = hash('sha256', $sourceIp);
            $model->status = WebhookEventStatus::Received;
            $model->retry_count = 0;
            $model->max_retry_count = 5;
            $model->platform_entity_id = $platformEntityId;
            $model->platform_entity_type = $platformEntityType;
            $model->received_at = CarbonImmutable::now();

            $this->repository->save($model);

            if ($paymentReceipt) {
                // Transactional E25 delivery intent closes commit-before-dispatch crash window.
                DB::connection('platform')->table('integration.admin_retry_intents')->insert([
                    'id' => (string) Uuid::uuid7(), 'webhook_event_id' => $model->id, 'created_at' => now(),
                ]);
            }

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
            try {
            ProcessWebhookEventJob::dispatch($event->id, $event->processing_generation, $event->retry_count)->onQueue('webhooks')->afterCommit();
            } catch (\Throwable $exception) {
                // Durable receipt/outbox exist. Never ask the provider to re-charge.
                // The operator can retry delivery using the original receipt identity.
                $event->status = WebhookEventStatus::Failed;
                $event->last_error = 'dispatch_unavailable';
                $this->repository->save($event);
                \Illuminate\Support\Facades\Log::warning('integration.webhook.dispatch_unavailable', ['receipt_id' => $event->id]);
            }
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

    private function verifyReceiptIdentity(WebhookEventModel $event, string $rawPayload): void
    {
        if ($event->payload_hash !== null && ! hash_equals($event->payload_hash, hash('sha256', $rawPayload))) {
            abort(409, 'Webhook identity already belongs to different evidence.');
        }
    }
}
