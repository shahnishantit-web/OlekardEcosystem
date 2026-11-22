<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;

/** Accepts only visibly synthetic evidence in local POC mode. */
final class LocalPocBootstrapApprovalEvidenceVerifier implements BootstrapApprovalEvidenceVerifier
{
    public function assertApproved(
        string $organisationId,
        string $administratorActorId,
        string $authorityType,
        array $approverActorIds,
        string $approvalReference,
    ): void {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true,
            503, 'The local Events POC is disabled.');
        abort_unless(str_starts_with($approvalReference, 'POC-LOCAL-')
            && count(array_unique($approverActorIds)) === 2
            && ! in_array($administratorActorId, $approverActorIds, true),
            403, 'POC bootstrap requires a local POC reference and two distinct approvers.');
    }
}
