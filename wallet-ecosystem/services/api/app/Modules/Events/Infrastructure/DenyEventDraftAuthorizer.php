<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;

final class DenyEventDraftAuthorizer implements EventDraftAuthorizer
{
    public function assertCanCreate(string $actorId, string $organisationId): void
    {
        abort(403, 'Approved event organiser authority is unavailable.');
    }

    public function assertCanRead(string $actorId, string $organisationId, string $eventId): void
    {
        abort(403, 'Approved event organiser authority is unavailable.');
    }

    public function assertCanUpdate(string $actorId, string $organisationId, string $eventId): void
    {
        abort(403, 'Approved event organiser authority is unavailable.');
    }
}
