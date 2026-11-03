<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\ValueObjects;

final readonly class CurrencyDefinitionSnapshot
{
    public function __construct(
        public string $code,
        public int $decimalPlaces,
        public bool $active,
        public ?string $updatedAt,
    ) {}
}
