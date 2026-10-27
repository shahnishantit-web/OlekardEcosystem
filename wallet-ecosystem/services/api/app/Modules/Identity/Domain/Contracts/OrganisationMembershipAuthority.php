<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/** Identity's server-side, reviewed actor-to-organisation authority. */
interface OrganisationMembershipAuthority
{
    public function assertActiveMembership(string $actorId, string $organisationId): void;
}
