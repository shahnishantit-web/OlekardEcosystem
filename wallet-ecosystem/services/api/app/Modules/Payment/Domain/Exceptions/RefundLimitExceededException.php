<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class RefundLimitExceededException extends \RuntimeException
{
    public function __construct(
        int $requestedMinor,
        int $confirmedMinor,
        int $originalMinor,
        string $currencyCode
    ) {
        $over = ($requestedMinor + $confirmedMinor) - $originalMinor;
        parent::__construct(
            "Refund of {$requestedMinor} {$currencyCode} would exceed the original payment of "
            . "{$originalMinor} {$currencyCode} (already refunded: {$confirmedMinor}, over by: {$over})."
        );
    }
}
