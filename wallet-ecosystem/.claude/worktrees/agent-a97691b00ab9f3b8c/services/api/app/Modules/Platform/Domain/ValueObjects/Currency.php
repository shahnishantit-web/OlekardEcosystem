<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

final class Currency
{
    private function __construct(
        private readonly string $code,
    ) {}

    public static function of(string $code): self
    {
        $normalized = strtoupper(trim($code));

        if (strlen($normalized) !== 3) {
            throw new \InvalidArgumentException(
                "Currency code must be exactly 3 characters; got '{$normalized}'.",
            );
        }

        return new self($normalized);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
