<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AdminActionApprovedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $aggregateId,
        ?string $actorId,
        string $correlationId,
        public readonly string $actionType,
        public readonly string $resourceId,
        public readonly string $makerId,
    ) {
        parent::__construct($aggregateId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'AdminApp.Action.Approved';
    }

    public function aggregateType(): string
    {
        return 'PendingAdminAction';
    }

    public function payload(): array
    {
        return [
            'pending_action_id' => $this->aggregateId,
            'action_type'       => $this->actionType,
            'resource_id'       => $this->resourceId,
            'maker_id'          => $this->makerId,
            'checker_id'        => $this->actorId,
        ];
    }
}
