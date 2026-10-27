<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Infrastructure;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority;

final class IdentityOrganisationMembershipVerifier implements OrganisationMembershipVerifier
{
    public function __construct(private readonly OrganisationMembershipAuthority $authority) {}

    public function assertActiveMembership(string $actorId, string $organisationId): void
    {
        $this->authority->assertActiveMembership($actorId, $organisationId);
    }
}
