<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\DTO;

final readonly class ExecuteQrPaymentCommand
{
    public function __construct(
        public string  $qrCodeId,
        public string  $customerWalletId,
        public ?string $amount,
        public string  $currency,
        public string  $idempotencyKey,
        public string  $correlationId,
        public ?string $stepUpToken,
    ) {}
}
