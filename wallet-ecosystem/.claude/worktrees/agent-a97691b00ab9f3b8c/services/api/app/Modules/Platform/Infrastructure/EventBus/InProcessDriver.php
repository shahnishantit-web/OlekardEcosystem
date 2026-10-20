<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\EventBus;

use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\Event;

final class InProcessDriver implements EventBusDriverInterface
{
    public function dispatch(OutboxEventModel $event): void
    {
        Event::dispatch($event->event_name, $event->payload ?? []);
    }
}
