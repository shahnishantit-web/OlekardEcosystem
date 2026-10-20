<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\DTO;

final readonly class QrFeeQuoteResult
{
    public function __construct(
        public int    $amount,
        public int    $fee,
        public int    $total,
        public string $currency,
    ) {}
}
