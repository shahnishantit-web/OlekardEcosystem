<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

/** Authorization seam for workflow actions; production remains deny-by-default until B-01. */
interface EventWorkflowAuthorizer
{
    public function assertCanSubmit(string $actorId, string $organisationId, string $eventId): void;

    public function assertCanApprovePublication(string $actorId, string $organisationId, string $eventId): void;

    public function assertCanApprovePrices(string $actorId, string $organisationId, string $eventId): void;

    public function assertCanPublish(string $actorId, string $organisationId, string $eventId): void;

    public function assertCanResumeSales(string $actorId, string $organisationId, string $eventId): void;
}
