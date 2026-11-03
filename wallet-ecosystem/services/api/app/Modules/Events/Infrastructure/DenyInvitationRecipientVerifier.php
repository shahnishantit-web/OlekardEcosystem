<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;

/** Identity does not yet expose the typed active-customer lookup needed for production invites. */
final class DenyInvitationRecipientVerifier implements InvitationRecipientVerifier
{
    public function assertActiveCustomerActor(string $actorId): void
    {
        abort(503, 'Invitation recipient verification is not configured.');
    }
}
