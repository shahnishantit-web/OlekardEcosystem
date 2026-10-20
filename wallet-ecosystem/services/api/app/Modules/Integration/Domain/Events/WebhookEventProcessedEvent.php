<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\CarbonImmutable;

final readonly class WebhookEventProcessedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $webhookEventId,
        public string $providerSlug,
        public string $eventType,
        private string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'integration.webhook.processed';
    }

    public function occurredAt(): string
    {
        return CarbonImmutable::now()->toIso8601String();
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'webhook_event_id' => $this->webhookEventId,
            'provider_slug'    => $this->providerSlug,
            'event_type'       => $this->eventType,
            'correlation_id'   => $this->correlationId,
            'occurred_at'      => $this->occurredAt(),
        ];
    }
}
