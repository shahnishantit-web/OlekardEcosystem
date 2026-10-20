<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class WithdrawalProcessing implements IntegrationEventInterface
{
    public function __construct(
        public string $withdrawalTransactionId,
        public string $walletId,
        public string $currencyCode,
        public int    $grossAmount,
        public string $channel,
        public string $walletHoldId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Withdrawal.Transaction.Processing'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'withdrawal_transaction_id' => $this->withdrawalTransactionId,
            'wallet_id'                 => $this->walletId,
            'currency_code'             => $this->currencyCode,
            'gross_amount'              => $this->grossAmount,
            'channel'                   => $this->channel,
            'wallet_hold_id'            => $this->walletHoldId,
            'occurred_at'               => $this->occurredAt,
            'correlation_id'            => $this->correlationId,
        ];
    }
}
