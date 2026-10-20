<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\CarbonImmutable;

final readonly class IntegrationRegisteredEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $integrationId,
        public string $providerType,
        public string $providerSlug,
        private string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'integration.provider.registered';
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
            'integration_id' => $this->integrationId,
            'provider_type'  => $this->providerType,
            'provider_slug'  => $this->providerSlug,
            'correlation_id' => $this->correlationId,
            'occurred_at'    => $this->occurredAt(),
        ];
    }
}
