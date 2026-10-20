<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ModuleNotImplementedException extends RuntimeException
{
    public function __construct(
        private readonly string $moduleName,
        private readonly string $methodName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Module {$moduleName} method {$methodName} is not implemented in Platform Foundation.",
            0,
            $previous,
        );
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }
}
