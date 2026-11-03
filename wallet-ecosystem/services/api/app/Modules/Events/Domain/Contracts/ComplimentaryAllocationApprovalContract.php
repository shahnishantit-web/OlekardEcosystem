<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;

interface ComplimentaryAllocationApprovalContract
{
    public function approvalEvidence(
        string $organisationId,
        string $eventId,
        string $categoryId,
    ): ComplimentaryAllocationApprovalEvidence;
}
