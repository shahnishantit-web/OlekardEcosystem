<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class BankReferenceGenerationException extends \RuntimeException
{
    public function __construct(int $maxAttempts)
    {
        parent::__construct(
            "Failed to generate a unique bank reference code after {$maxAttempts} attempts."
        );
    }
}
