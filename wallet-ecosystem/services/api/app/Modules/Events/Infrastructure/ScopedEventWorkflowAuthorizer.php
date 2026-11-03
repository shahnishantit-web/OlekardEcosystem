<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Application\ScopedEventAuthorization;
use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;
use App\Modules\Events\Domain\Permissions\EventPermissions;

final class ScopedEventWorkflowAuthorizer implements EventWorkflowAuthorizer
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    public function assertCanSubmit(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::SUBMIT);
    }

    public function assertCanApprovePublication(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::REVIEW);
    }

    public function assertCanApprovePrices(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::PRICE_APPROVE);
    }

    public function assertCanPublish(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::PUBLISH);
    }

    public function assertCanResumeSales(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::SALES_RESUME);
    }
}
