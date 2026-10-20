<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ModuleConnectionUnavailableException extends RuntimeException
{
    public function __construct(
        private readonly string $moduleName,
        private readonly string $connectionName,
        private readonly string $correlationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Module '{$moduleName}' named database connection '{$connectionName}' is unavailable.",
            0,
            $previous,
        );
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}
