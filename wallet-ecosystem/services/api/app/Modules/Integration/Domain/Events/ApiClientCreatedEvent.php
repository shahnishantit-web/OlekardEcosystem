<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\CarbonImmutable;

final readonly class ApiClientCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $apiClientId,
        public string $name,
        private string $correlationId,
        private string $occurredAt = '',
    ) {}

    public function eventName(): string
    {
        return 'integration.api_client.created';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt ?: CarbonImmutable::now()->toIso8601String();
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'api_client_id' => $this->apiClientId,
            'name'          => $this->name,
            'correlation_id' => $this->correlationId,
            'occurred_at'   => $this->occurredAt(),
        ];
    }
}
