<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Contracts;

/** An authoritative foundation service must prove active actor membership. */
interface OrganisationMembershipVerifier
{
    public function assertActiveMembership(string $actorId, string $organisationId): void;
}
