<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class UnsafeProviderSwitchException extends \DomainException
{
    public function __construct(
        public readonly string $intentId,
        public readonly string $unknownProvider,
        public readonly string $requestedProvider,
    ) {
        parent::__construct(
            "Cannot switch to provider '{$requestedProvider}' while a prior attempt via '{$unknownProvider}' is in UNKNOWN state for intent '{$intentId}'. Resolve the existing attempt before retrying with a different provider."
        );
    }
}
