<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class ServicePaymentCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $transactionId,
        public string $serviceId,
        public string $customerId,
        public string $walletId,
        public string $currencyCode,
        public int    $amount,
        public int    $feeAmount,
        public string $receiptReference,
        public string $providerReference,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Service.Payment.Completed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'transaction_id'    => $this->transactionId,
            'service_id'        => $this->serviceId,
            'customer_id'       => $this->customerId,
            'wallet_id'         => $this->walletId,
            'currency_code'     => $this->currencyCode,
            'amount'            => $this->amount,
            'fee_amount'        => $this->feeAmount,
            'receipt_reference' => $this->receiptReference,
            'provider_reference' => $this->providerReference,
            'occurred_at'       => $this->occurredAt,
            'correlation_id'    => $this->correlationId,
        ];
    }
}
