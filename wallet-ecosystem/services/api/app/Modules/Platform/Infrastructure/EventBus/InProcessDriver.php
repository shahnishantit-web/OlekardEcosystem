<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\EventBus;

use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\Event;

final class InProcessDriver implements EventBusDriverInterface
{
    public function dispatch(OutboxEventModel $event): void
    {
        if (in_array((string) $event->getAttribute('event_name'), ['SUPPORT_CASE_ESCALATED', 'DISPUTE_RESOLVED_WITH_REFUND'], true)) {
            Event::dispatch((string) $event->getAttribute('event_name'), [(array) $event->getAttribute('payload')]);

            return;
        }
        Event::dispatch((string) $event->getAttribute('event_name'), (array) $event->getAttribute('payload'));
    }
}
