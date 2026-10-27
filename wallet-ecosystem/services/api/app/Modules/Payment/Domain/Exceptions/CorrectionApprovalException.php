<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class CorrectionApprovalException extends \RuntimeException
{
    public static function selfApprovalNotPermitted(): self
    {
        return new self('A correction request cannot be approved by the same actor who submitted it.');
    }

    public static function notInPendingState(string $currentStatus): self
    {
        return new self(
            "Cannot approve or reject a correction request with status '{$currentStatus}'. Only 'pending_approval' requests can be reviewed."
        );
    }
}
