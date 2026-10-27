<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

/** Must be backed by approved organiser authority before any draft can be accessed. */
interface EventDraftAuthorizer
{
    public function assertCanManageDraft(string $actorId, string $organisationId): void;
}
