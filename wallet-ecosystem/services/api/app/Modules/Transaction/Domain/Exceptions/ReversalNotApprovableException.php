<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Exceptions;

use RuntimeException;

final class ReversalNotApprovableException extends RuntimeException
{
    public static function wrongStatus(string $status): self
    {
        return new self("Reversal cannot be approved from status [{$status}].");
    }

    public static function makerCheckerViolation(): self
    {
        return new self("Approver must be different from the reversal requester (maker-checker rule).");
    }
}
