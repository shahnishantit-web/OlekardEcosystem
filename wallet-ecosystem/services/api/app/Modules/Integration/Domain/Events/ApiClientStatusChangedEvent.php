<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\CarbonImmutable;

final readonly class ApiClientStatusChangedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $apiClientId,
        public string $previousStatus,
        public string $newStatus,
        private string $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'integration.api_client.status_changed';
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
            'api_client_id'   => $this->apiClientId,
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->newStatus,
            'correlation_id'  => $this->correlationId,
            'occurred_at'     => $this->occurredAt(),
        ];
    }
}
