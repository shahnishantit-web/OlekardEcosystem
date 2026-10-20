<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class MfaDisabledEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $userId,
        ?string $actorId,
        string $correlationId,
        private readonly string $actorType,
        private readonly string $method,
        ?string $causationId = null,
    ) {
        parent::__construct($userId, $actorId, $correlationId, $causationId);
    }

    public function aggregateType(): string
    {
        return 'Identity.MFA';
    }

    public function eventName(): string
    {
        return 'Identity.MFA.Disabled';
    }

    public function payload(): array
    {
        return [
            'user_id'    => $this->aggregateId(),
            'actor_type' => $this->actorType,
            'method'     => $this->method,
        ];
    }
}
