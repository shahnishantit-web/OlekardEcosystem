<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Contracts;

final class RiskResult
{
    private function __construct(
        public readonly bool   $approved,
        public readonly string $reason = '',
    ) {}

    public static function approved(): self
    {
        return new self(approved: true);
    }

    public static function rejected(string $reason): self
    {
        return new self(approved: false, reason: $reason);
    }
}
