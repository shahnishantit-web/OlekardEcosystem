<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FeeRuleCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ruleId,
        public string $transactionType,
        public string $feeType,
        public string $currency,
        public string $subjectType,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Fees.FeeRule.Created'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'rule_id'          => $this->ruleId,
            'transaction_type' => $this->transactionType,
            'fee_type'         => $this->feeType,
            'currency'         => $this->currency,
            'subject_type'     => $this->subjectType,
            'occurred_at'      => $this->occurredAt,
            'correlation_id'   => $this->correlationId,
        ];
    }
}
