<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

final class CurrencyReviewRequired extends \RuntimeException
{
    public function __construct(public readonly string $eventId, public readonly string $reasonCode)
    {
        parent::__construct('Event currency requires operator review.');
    }
}
