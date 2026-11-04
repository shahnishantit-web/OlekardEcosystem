<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\ValueObjects\OrganiserRepresentativeEvidence;

/** Identity-owned authority boundary; membership alone is not representative authority. */
interface OrganiserRepresentativeVerifier
{
    public function verifyForEventTerms(string $organisationId, string $actorId, string $eventId): OrganiserRepresentativeEvidence;
}
