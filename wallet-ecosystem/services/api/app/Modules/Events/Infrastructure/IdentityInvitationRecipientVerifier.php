<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;

/** Uses Identity's typed actor lookup and fails closed without disclosing recipient details. */
final class IdentityInvitationRecipientVerifier implements InvitationRecipientVerifier
{
    public function __construct(private readonly IdentityModuleContract $identity) {}

    public function assertActiveCustomerActor(string $actorId): void
    {
        try {
            $activeCustomerId = $this->identity->findActiveCustomerActorId($actorId);
        } catch (\Throwable) {
            abort(503, 'Invitation recipient verification is unavailable.');
        }

        abort_unless(is_string($activeCustomerId) && hash_equals($actorId, $activeCustomerId),
            422, 'Invitation recipient is unavailable.');
    }
}
