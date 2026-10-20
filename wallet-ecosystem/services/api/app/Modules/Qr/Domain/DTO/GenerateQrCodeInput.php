<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\DTO;

use App\Modules\Qr\Domain\Enums\QrCodeType;

final readonly class GenerateQrCodeInput
{
    public function __construct(
        public QrCodeType $type,
        public ?string    $label,
        public ?string    $amount,
        public string     $currency,
        public ?string    $merchantRef,
        public ?int       $expirySeconds,
    ) {}
}
