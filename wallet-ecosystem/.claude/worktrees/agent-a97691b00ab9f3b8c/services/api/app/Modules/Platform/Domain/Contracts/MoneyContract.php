<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface MoneyContract
{
    /** amount() returns integer in smallest currency unit (e.g., thebe for BWP). */
    public function amount(): int;

    public function currency(): CurrencyContract;

    public function add(MoneyContract $other): static;

    public function subtract(MoneyContract $other): static;

    public function isZero(): bool;

    public function isPositive(): bool;

    public function equals(MoneyContract $other): bool;

    public function toDisplayString(): string;
}
