<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ServiceCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $providerCode,
        public string $category,
        public string $status,
        public string $actorId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Service.Created'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'service_id'    => $this->serviceId,
            'name'          => $this->name,
            'provider_code' => $this->providerCode,
            'category'      => $this->category,
            'status'        => $this->status,
            'actor_id'      => $this->actorId,
            'occurred_at'   => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
