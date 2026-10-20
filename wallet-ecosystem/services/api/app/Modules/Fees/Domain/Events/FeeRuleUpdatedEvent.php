<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FeeRuleUpdatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ruleId,
        public array  $changes,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Fees.FeeRule.Updated'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'rule_id'        => $this->ruleId,
            'changes'        => $this->changes,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
