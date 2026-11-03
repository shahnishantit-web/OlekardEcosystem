<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

final readonly class EventPricingLine
{
    public function __construct(
        public string $categoryId,
        public string $categoryName,
        public int $quantity,
        public int $unitPriceMinor,
    ) {
        if ($categoryId === '' || $categoryName === '' || $quantity < 1 || $unitPriceMinor < 1) {
            throw new \InvalidArgumentException('Paid event pricing lines require category, positive quantity and positive unit price.');
        }
    }

    /** @return array{category_id:string,category_name:string,quantity:int,unit_price_minor:int,subtotal_minor:int} */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unitPriceMinor,
            'subtotal_minor' => $this->quantity * $this->unitPriceMinor,
        ];
    }
}
