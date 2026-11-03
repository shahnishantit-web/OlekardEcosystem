<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

/** Must be backed by approved organiser authority before any draft can be accessed. */
interface EventDraftAuthorizer
{
    public function assertCanCreate(string $actorId, string $organisationId): void;

    public function assertCanRead(string $actorId, string $organisationId, string $eventId): void;

    public function assertCanUpdate(string $actorId, string $organisationId, string $eventId): void;
}
