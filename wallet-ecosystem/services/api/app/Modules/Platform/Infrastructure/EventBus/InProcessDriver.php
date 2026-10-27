<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\EventBus;

use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\Event;

final class InProcessDriver implements EventBusDriverInterface
{
    public function dispatch(OutboxEventModel $event): void
    {
        // Wrap payload in an outer array so all listeners receive handle(array $payload)
        // via positional arg expansion — consistent with DeliverInvestigationEventsJob pattern.
        Event::dispatch((string) $event->getAttribute('event_name'), [(array) $event->getAttribute('payload')]);
    }
}
