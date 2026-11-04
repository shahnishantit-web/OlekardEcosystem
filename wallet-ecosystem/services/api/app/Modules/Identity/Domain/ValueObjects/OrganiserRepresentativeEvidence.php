<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

use DateTimeImmutable;

/** Purpose-specific, immutable evidence that an actor may bind an organisation to terms. */
final readonly class OrganiserRepresentativeEvidence
{
    public function __construct(
        public string $organisationId,
        public string $actorId,
        public string $eventId,
        public string $verificationId,
        public int $version,
        public string $evidenceReference,
        public DateTimeImmutable $verifiedAt,
        public DateTimeImmutable $validUntil,
    ) {
        if ($organisationId === '' || $actorId === '' || $eventId === '' || $verificationId === ''
            || $version < 1 || $evidenceReference === '' || $verifiedAt >= $validUntil) {
            throw new \InvalidArgumentException('Organiser representative evidence is incomplete.');
        }
    }

    public function assertCurrentFor(string $organisationId, string $actorId, string $eventId, DateTimeImmutable $now): void
    {
        if ($this->organisationId !== $organisationId || $this->actorId !== $actorId || $this->eventId !== $eventId
            || $this->verifiedAt > $now || $this->validUntil <= $now) {
            throw new \DomainException('Current purpose-specific organiser representative evidence is required.');
        }
    }
}
