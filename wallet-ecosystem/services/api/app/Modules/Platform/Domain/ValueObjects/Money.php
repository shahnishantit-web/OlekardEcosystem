<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Exceptions\CurrencyMismatchException;

final class Money
{
    public function __construct(
        private readonly int $amount,
        private readonly Currency $currency,
    ) {}

    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        if (! $this->currency->equals($other->currency)) {
            throw new CurrencyMismatchException($this->currency, $other->currency);
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        if (! $this->currency->equals($other->currency)) {
            throw new CurrencyMismatchException($this->currency, $other->currency);
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency->equals($other->currency);
    }

    public function toArray(): array
    {
        return [
            'amount'   => $this->amount,
            'currency' => $this->currency->getCode(),
        ];
    }
}
