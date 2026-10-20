<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AdminCaseEvent implements IntegrationEventInterface
{
    public function __construct(private string $name, private array $payload) {}

    public function eventName(): string
    {
        return $this->name;
    }

    public function occurredAt(): string
    {
        return $this->payload['occurred_at'];
    }

    public function correlationId(): string
    {
        return $this->payload['correlation_id'];
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
