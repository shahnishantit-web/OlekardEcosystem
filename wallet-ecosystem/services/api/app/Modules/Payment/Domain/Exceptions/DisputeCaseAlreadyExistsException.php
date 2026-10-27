<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class DisputeCaseAlreadyExistsException extends RuntimeException
{
    public function __construct(string $providerCode, string $providerDisputeReference, string $existingCaseId)
    {
        parent::__construct(
            "Dispute case already exists for {$providerCode}/{$providerDisputeReference}: {$existingCaseId}"
        );
    }
}
