<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

/**
 * Thrown when the actor attempting to approve is the same as the actor who submitted.
 * FR-013: Two-person approval — approver ID must differ from submitter ID.
 */
final class ApprovalConflictException extends DomainException
{
    public function __construct(string $actorId)
    {
        parent::__construct(
            sprintf(
                'Actor "%s" cannot approve their own submission. Two-person approval requires a different approver.',
                $actorId,
            )
        );
    }
}
