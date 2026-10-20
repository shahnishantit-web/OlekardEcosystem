<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class PinCreatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string  $userId,
        ?string $actorId,
        string  $correlationId,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string { return 'Identity.Credential'; }
    public function eventName(): string     { return 'Identity.Credential.PinCreated'; }

    public function payload(): array
    {
        return ['user_id' => $this->aggregateId()];
    }
}
