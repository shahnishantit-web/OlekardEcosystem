<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface IntegrationEventInterface
{
    public function eventId(): string;

    public function aggregateId(): string;

    public function aggregateType(): string;

    public function actorId(): ?string;

    public function correlationId(): string;

    public function causationId(): ?string;

    public function occurredAt(): \DateTimeImmutable;

    public function eventName(): string;

    public function eventVersion(): string;

    public function payload(): array;

    public function metadata(): array;
}
