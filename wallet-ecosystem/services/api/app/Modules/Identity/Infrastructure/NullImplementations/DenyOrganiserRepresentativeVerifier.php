<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\NullImplementations;

use App\Modules\Identity\Domain\Contracts\OrganiserRepresentativeVerifier;
use App\Modules\Identity\Domain\ValueObjects\OrganiserRepresentativeEvidence;

final class DenyOrganiserRepresentativeVerifier implements OrganiserRepresentativeVerifier
{
    public function verifyForEventTerms(string $organisationId, string $actorId, string $eventId): OrganiserRepresentativeEvidence
    {
        throw new \DomainException('Authoritative organiser representative verification is not configured.');
    }
}
