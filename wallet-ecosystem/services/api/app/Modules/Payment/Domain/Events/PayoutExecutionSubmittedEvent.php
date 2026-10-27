<?php

declare(strict_types=1);

// STUB — blocked pending AD-14 resolution.
// Replace this stub with the full implementation once the payout adapter contract is finalised.

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Integration\Domain\Contracts\IntegrationEventInterface;
use DateTimeImmutable;

final class PayoutExecutionSubmittedEvent implements IntegrationEventInterface
{
    public function __construct(
        private readonly string $payoutRequestId,
        private readonly string $operationId,
        private readonly string $providerCode,
        private readonly string $correlationId,
        private readonly DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.payout.execution_submitted';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'payout_request_id' => $this->payoutRequestId,
            'operation_id'      => $this->operationId,
            'provider_code'     => $this->providerCode,
        ];
    }
}
