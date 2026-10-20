<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Contracts;

final class WithdrawalRiskResult
{
    private function __construct(
        private readonly bool $approved,
        private readonly ?string $reason,
    ) {}

    public static function approved(): self
    {
        return new self(true, null);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, $reason);
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
