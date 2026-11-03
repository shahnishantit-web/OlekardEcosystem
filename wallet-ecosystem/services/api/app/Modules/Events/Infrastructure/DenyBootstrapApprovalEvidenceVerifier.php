<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;

/** No governance-reference service is currently available; bootstrap remains fail-closed. */
final class DenyBootstrapApprovalEvidenceVerifier implements BootstrapApprovalEvidenceVerifier
{
    public function assertApproved(
        string $organisationId,
        string $administratorActorId,
        string $authorityType,
        array $approverActorIds,
        string $approvalReference,
    ): void {
        abort(403, 'No approved governance evidence verifier is configured; assignment bootstrap is disabled.');
    }
}
