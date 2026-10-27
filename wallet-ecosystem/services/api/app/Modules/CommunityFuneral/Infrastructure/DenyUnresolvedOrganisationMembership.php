<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Infrastructure;

use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;

/** The current Identity schema has no organisation-membership authority. */
final class DenyUnresolvedOrganisationMembership implements OrganisationMembershipVerifier
{
    public function assertActiveMembership(string $actorId, string $organisationId): void
    {
        abort(403, 'Authoritative organisation membership is unavailable.');
    }
}
