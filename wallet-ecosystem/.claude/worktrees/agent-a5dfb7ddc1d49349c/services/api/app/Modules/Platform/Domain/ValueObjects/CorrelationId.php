<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use Ramsey\Uuid\Uuid;

final class CorrelationId
{
    private function __construct(
        private readonly string $value,
    ) {}

    public static function generate(): self
    {
        return new self((string) Uuid::uuid7());
    }

    public static function fromString(string $value): self
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('CorrelationId value must not be empty.');
        }

        return new self($value);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
