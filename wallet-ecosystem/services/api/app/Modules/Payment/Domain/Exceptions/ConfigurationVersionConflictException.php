<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class ConfigurationVersionConflictException extends \RuntimeException
{
    public function __construct(int $expectedVersion, int $currentVersion)
    {
        parent::__construct(
            "Configuration version conflict: expected version {$expectedVersion}, found {$currentVersion}. Draft is stale."
        );
    }
}
