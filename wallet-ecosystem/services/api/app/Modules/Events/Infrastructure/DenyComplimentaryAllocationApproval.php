<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** No authoritative complimentary-allocation approval contract is currently registered. */
final class DenyComplimentaryAllocationApproval implements ComplimentaryAllocationApprovalContract
{
    public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
    {
        throw new HttpException(503, 'Complimentary allocation approval is not configured.');
    }
}
