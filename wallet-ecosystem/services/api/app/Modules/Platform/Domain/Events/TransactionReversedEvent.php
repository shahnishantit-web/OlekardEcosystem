<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

/**
 * E27 T076 — Fired when a financial transaction is reversed through orphan auto-recovery.
 *
 * Dispatched by FundingOrphanRecoveryService, TransferOrphanRecoveryService,
 * and WithdrawalOrphanRecoveryService when provider confirms failure or no
 * financial impact exists and the transaction is marked Failed/Reversed.
 */
final readonly class TransactionReversedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $transactionId,
        public string $moduleType,
        public string $walletId,
        public int $amount,
        public string $currency,
        public string $correlationId,
        public string $reversedAt,
    ) {}

    public function eventName(): string
    {
        return 'Platform.TransactionReversed';
    }

    public function occurredAt(): string
    {
        return $this->reversedAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'module_type' => $this->moduleType,
            'wallet_id' => $this->walletId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'correlation_id' => $this->correlationId,
            'reversed_at' => $this->reversedAt,
        ];
    }
}
