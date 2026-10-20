<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TbdConfigurationException extends RuntimeException
{
    private const RETRY_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly string $configKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Configuration key '{$configKey}' is TBD and has not been resolved.",
            0,
            $previous,
        );
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function getRetryAfter(): int
    {
        return self::RETRY_AFTER_SECONDS;
    }
}
