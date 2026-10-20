<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SecurityUnauthorizedAccessAttemptEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $actorId,
        public string $resource,
        public string $resourceId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'security.unauthorized_access_attempt'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'actor_id'       => $this->actorId,
            'resource'       => $this->resource,
            'resource_id'    => $this->resourceId,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
