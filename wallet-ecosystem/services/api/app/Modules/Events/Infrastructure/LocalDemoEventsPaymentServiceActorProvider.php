<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Identity\Domain\Contracts\ServiceActor;

/** Synthetic service identity limited to local paid-event demo confirmation and acknowledgement. */
final class LocalDemoEventsPaymentServiceActorProvider implements EventsPaymentServiceActorProvider
{
    public function actorFor(string $organisationId): ServiceActor
    {
        if (! app()->environment('local')) {
            throw new \LogicException('The local demo Events service identity is disabled outside local.');
        }

        return new ServiceActor('events', 'SERVICE', [
            'payment.confirmation.read:'.$organisationId.':paid_event_ticket',
            'payment.fulfilment.ack:'.$organisationId.':paid_event_ticket',
        ], null);
    }
}
