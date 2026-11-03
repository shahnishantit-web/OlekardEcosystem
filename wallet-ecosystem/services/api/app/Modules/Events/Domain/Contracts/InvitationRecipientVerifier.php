<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

interface InvitationRecipientVerifier
{
    /** Verify that the target exists, is active, and has CUSTOMER actor type. */
    public function assertActiveCustomerActor(string $actorId): void;
}
