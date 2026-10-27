<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface OutboxQueueHealthServiceInterface
{
    public function getHealthMetrics(?string $eventName = null): array;
}
