<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Identity\Domain\Contracts\ServiceActor;

interface EventsPaymentServiceActorProvider
{
    public function actorFor(string $organisationId): ServiceActor;
}
