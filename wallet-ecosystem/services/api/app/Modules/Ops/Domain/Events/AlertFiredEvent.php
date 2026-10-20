<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class AlertFiredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $alertId,
        private readonly string $ruleName,
        private readonly string $severity,
        private readonly string $conditionSummary,
        private readonly array $notificationChannels,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId: $alertId,
            actorId: $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string
    {
        return 'Ops.Alert.Fired';
    }

    public function aggregateType(): string
    {
        return 'Alert';
    }

    public function payload(): array
    {
        return [
            'alert_id'             => $this->aggregateId,
            'rule_name'            => $this->ruleName,
            'severity'             => $this->severity,
            'condition_summary'    => $this->conditionSummary,
            'notification_channels'=> $this->notificationChannels,
            'occurred_at'          => $this->occurredAt(),
        ];
    }
}
