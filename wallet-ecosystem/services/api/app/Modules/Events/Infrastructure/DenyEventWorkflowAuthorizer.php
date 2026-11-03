<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;

final class DenyEventWorkflowAuthorizer implements EventWorkflowAuthorizer
{
    public function assertCanSubmit(string $actorId, string $organisationId, string $eventId): void
    {
        $this->deny();
    }

    public function assertCanApprovePublication(string $actorId, string $organisationId, string $eventId): void
    {
        $this->deny();
    }

    public function assertCanApprovePrices(string $actorId, string $organisationId, string $eventId): void
    {
        $this->deny();
    }

    public function assertCanPublish(string $actorId, string $organisationId, string $eventId): void
    {
        $this->deny();
    }

    public function assertCanResumeSales(string $actorId, string $organisationId, string $eventId): void
    {
        $this->deny();
    }

    private function deny(): never
    {
        abort(403, 'Approved event workflow authority is unavailable.');
    }
}
