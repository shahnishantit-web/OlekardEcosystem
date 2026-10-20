<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class DuplicateAttemptException extends \RuntimeException
{
    public function __construct(string $intentId)
    {
        parent::__construct(
            "PaymentIntent [{$intentId}] already has an active attempt. "
            . 'Wait for the current attempt to reach a terminal state before initiating a new one.'
        );
    }
}
