<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatFundingAccountNotConfiguredException extends \RuntimeException
{
    public function __construct(
        public readonly string $configKey,
        public readonly string $errorCode = 'FLOAT_FUNDING_ACCOUNT_NOT_CONFIGURED',
    ) {
        parent::__construct(
            "Float funding account not configured: [{$configKey}]. Resolve TBD-E13-001 before funding."
        );
    }

    public static function forKey(string $configKey): self
    {
        return new self($configKey);
    }
}
