<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ServiceDeactivatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $serviceId,
        public string $reason,
        public string $actorId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Service.Deactivated'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'service_id'     => $this->serviceId,
            'reason'         => $this->reason,
            'actor_id'       => $this->actorId,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
