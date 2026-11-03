<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Application\ScopedEventAuthorization;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\Permissions\EventPermissions;

final class ScopedEventDraftAuthorizer implements EventDraftAuthorizer
{
    public function __construct(private readonly ScopedEventAuthorization $authorization) {}

    public function assertCanCreate(string $actorId, string $organisationId): void
    {
        $this->authorization->assertOrganisation($actorId, $organisationId, EventPermissions::CREATE);
    }

    public function assertCanRead(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::READ);
    }

    public function assertCanUpdate(string $actorId, string $organisationId, string $eventId): void
    {
        $this->authorization->assertEvent($actorId, $organisationId, $eventId, EventPermissions::UPDATE);
    }
}
