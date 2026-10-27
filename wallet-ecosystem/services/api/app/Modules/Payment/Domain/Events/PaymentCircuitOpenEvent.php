<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

final readonly class PaymentCircuitOpenEvent implements \App\Modules\Platform\Domain\Contracts\IntegrationEventInterface
{
    private string $occurredAt;
    public function __construct(
        public string $providerCode,
        public int    $consecutiveFailures,
        public int    $resetMinutes,
        public string $correlationId,
    ) {
        $this->occurredAt = (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    public function eventName(): string { return 'payment.circuit.open'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function toArray(): array
    {
        return ['provider_code' => $this->providerCode, 'consecutive_failures' => $this->consecutiveFailures,
            'reset_minutes' => $this->resetMinutes, 'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt];
    }
}
