<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class ProviderCircuitOpenException extends \RuntimeException
{
    public function __construct(string $providerCode, int $resetMinutes)
    {
        parent::__construct(
            "Payment provider circuit is open for '{$providerCode}'. "
            . "New attempts are blocked. Circuit resets in {$resetMinutes} minutes."
        );
    }
}
