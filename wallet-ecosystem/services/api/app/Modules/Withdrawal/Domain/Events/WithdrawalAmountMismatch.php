<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class WithdrawalAmountMismatch implements IntegrationEventInterface
{
    public function __construct(
        public string $withdrawalTransactionId,
        public string $walletId,
        public string $currencyCode,
        public int    $grossAmount,
        public string $channel,
        public int    $expectedAmount,
        public int    $callbackAmount,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Withdrawal.Transaction.AmountMismatch'; }
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
            'expected_amount'           => $this->expectedAmount,
            'callback_amount'           => $this->callbackAmount,
            'occurred_at'               => $this->occurredAt,
            'correlation_id'            => $this->correlationId,
        ];
    }
}
