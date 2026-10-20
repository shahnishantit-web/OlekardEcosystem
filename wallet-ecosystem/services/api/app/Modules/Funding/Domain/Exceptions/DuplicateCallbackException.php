<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class DuplicateCallbackException extends \RuntimeException
{
    public function __construct(string $providerReferenceId)
    {
        parent::__construct("Duplicate provider callback for reference [{$providerReferenceId}].");
    }
}
