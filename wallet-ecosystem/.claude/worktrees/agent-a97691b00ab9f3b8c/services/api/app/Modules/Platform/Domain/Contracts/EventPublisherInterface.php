<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface EventPublisherInterface
{
    public function publish(IntegrationEventInterface $event): void;

    /** @param IntegrationEventInterface[] $events */
    public function publishAll(array $events): void;
}
