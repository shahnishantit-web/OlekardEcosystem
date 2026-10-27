<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/** Shared-database monolith: persist the common outbox in the domain transaction. */
interface ConnectionAwareEventPublisherInterface extends EventPublisherInterface
{
    public function publishOnConnection(IntegrationEventInterface $event, string $connection): void;
}
