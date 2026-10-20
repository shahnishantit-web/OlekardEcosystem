<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Ramsey\Uuid\Uuid;

/**
 * Event naming convention: {Domain}.{Aggregate}.{PastTenseVerb} dot-notation PascalCase.
 * Example: Wallet.Transfer.Completed
 */
abstract class BaseIntegrationEvent implements IntegrationEventInterface
{
    private readonly string $eventId;

    protected readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        protected readonly string $aggregateId,
        protected readonly ?string $actorId,
        protected readonly string $correlationId,
        protected readonly ?string $causationId = null,
    ) {
        $this->eventId    = (string) Uuid::uuid7();
        $this->occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    final public function actorId(): ?string
    {
        return $this->actorId;
    }

    final public function correlationId(): string
    {
        return $this->correlationId;
    }

    final public function causationId(): ?string
    {
        return $this->causationId;
    }

    final public function occurredAt(): string
    {
        return $this->occurredAt->format(\DateTimeInterface::ATOM);
    }

    public function occurredAtImmutable(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventVersion(): string
    {
        return '1';
    }

    public function toArray(): array
    {
        return $this->payload();
    }

    abstract public function payload(): array;

    abstract public function aggregateType(): string;

    public function metadata(): array
    {
        return [
            'event_id'       => $this->eventId,
            'event_version'  => $this->eventVersion(),
            'correlation_id' => $this->correlationId,
            'causation_id'   => $this->causationId,
            'occurred_at'    => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
