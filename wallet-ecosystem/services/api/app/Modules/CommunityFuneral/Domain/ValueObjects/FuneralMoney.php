<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\ValueObjects;

final readonly class FuneralMoney
{
    public function __construct(
        public int $amountMinor,
        public string $currencyCode = 'BWP'
    ) {
        if ($this->amountMinor < 0) {
            throw new \InvalidArgumentException('Monetary amount minor cannot be negative.');
        }
    }
}
