<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use Illuminate\Auth\Access\AuthorizationException;

/** Resolves a secret-managed Identity service token; absent token fails closed. */
final class ConfiguredEventsPaymentServiceActorProvider implements EventsPaymentServiceActorProvider
{
    public function __construct(private readonly IdentityModuleContract $identity) {}

    public function actorFor(string $organisationId): ServiceActor
    {
        $token = (string) config('services.events.payment_service_token', '');
        if ($token === '') {
            throw new AuthorizationException('Events service identity is not configured.');
        }
        $actor = $this->identity->resolveServiceActor($token);
        $required = [
            'payment.confirmation.read:'.$organisationId.':paid_event_ticket',
            'payment.fulfilment.ack:'.$organisationId.':paid_event_ticket',
        ];
        if ($actor === null || $actor->serviceId !== 'events'
            || array_diff($required, $actor->scopes) !== []) {
            throw new AuthorizationException('Events service identity lacks scoped evidence and fulfilment acknowledgement authority.');
        }

        return $actor;
    }
}
