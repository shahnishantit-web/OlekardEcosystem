<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\EventBus;

use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;

interface EventBusDriverInterface
{
    public function dispatch(OutboxEventModel $event): void;
}
