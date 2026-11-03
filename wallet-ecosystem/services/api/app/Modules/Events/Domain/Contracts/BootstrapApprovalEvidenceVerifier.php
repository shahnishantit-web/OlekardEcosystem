<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

/** Verifies an approved, durable governance record before first-authority bootstrap. */
interface BootstrapApprovalEvidenceVerifier
{
    /** @param list<string> $approverActorIds */
    public function assertApproved(
        string $organisationId,
        string $administratorActorId,
        string $authorityType,
        array $approverActorIds,
        string $approvalReference,
    ): void;
}
