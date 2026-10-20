<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class CommissionRuleCreatedEvent extends BaseIntegrationEvent
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $name,
        public readonly string $ruleType,
        public readonly string $appliesTo,
        public readonly int    $rate,
        string $correlationId,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateId:   $ruleId,
            actorId:       $actorId,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string { return 'Commission.Rule.Created'; }

    public function aggregateType(): string { return 'commission_rule'; }

    public function payload(): array
    {
        return [
            'rule_id'    => $this->ruleId,
            'name'       => $this->name,
            'rule_type'  => $this->ruleType,
            'applies_to' => $this->appliesTo,
            'rate'       => $this->rate,
        ];
    }
}
