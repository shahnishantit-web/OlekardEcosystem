<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class InvalidStateTransitionException extends \RuntimeException
{
    public function __construct(string $entity, string $from, string $to)
    {
        parent::__construct(
            "Invalid {$entity} state transition: [{$from}] → [{$to}]."
        );
    }
}
