<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Carbon\Carbon;

final readonly class QrPaymentCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $merchantId,
        public string $customerId,
        public int    $amount,
        public int    $fee,
        public string $currency,
        public string $paymentReference,
        public string $receiptRef,
        public Carbon $occurredAt,
    ) {}

    public function eventName(): string     { return 'Qr.Payment.Completed'; }
    public function occurredAt(): string    { return $this->occurredAt->toIso8601String(); }
    public function correlationId(): string { return $this->paymentReference; }

    public function toArray(): array
    {
        return [
            'merchant_id'       => $this->merchantId,
            'customer_id'       => $this->customerId,
            'amount'            => $this->amount,
            'fee'               => $this->fee,
            'currency'          => $this->currency,
            'payment_reference' => $this->paymentReference,
            'receipt_ref'       => $this->receiptRef,
            'occurred_at'       => $this->occurredAt->toIso8601String(),
        ];
    }
}
