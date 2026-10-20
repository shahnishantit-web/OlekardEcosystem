<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ComplianceStatusChangedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string  $userId,
        public string  $fieldChanged,
        public mixed   $beforeValue,
        public mixed   $afterValue,
        public string  $occurredAtIso,
        public string  $correlationId,
    ) {}

    public function eventName(): string
    {
        return 'audit.compliance_status.changed.v1';
    }

    public function occurredAt(): string
    {
        return $this->occurredAtIso;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'user_id'      => $this->userId,
            'field_changed' => $this->fieldChanged,
            'before_value' => $this->beforeValue,
            'after_value'  => $this->afterValue,
            'occurred_at'  => $this->occurredAtIso,
            'correlation_id' => $this->correlationId,
        ];
    }
}
