<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\ValueObjects\PayoutQualificationSummary;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class PayoutQualifiedEvent implements IntegrationEventInterface
{
    public function __construct(
        private PayoutQualificationSummary $summary,
    ) {}

    public function eventName(): string
    {
        return 'payment.reconciliation.payout_qualified';
    }

    public function occurredAt(): string
    {
        return $this->summary->computedAt->toIso8601String();
    }

    public function correlationId(): string
    {
        return $this->summary->statementId;
    }

    public function aggregateType(): string
    {
        return 'provider_statement';
    }

    public function aggregateId(): string
    {
        return $this->summary->statementId;
    }

    public function idempotencyKey(): string
    {
        return "payout.qualified:{$this->summary->statementId}:{$this->summary->versionHash}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->summary->toArray(), [
            'event' => $this->eventName(),
        ]);
    }
}
