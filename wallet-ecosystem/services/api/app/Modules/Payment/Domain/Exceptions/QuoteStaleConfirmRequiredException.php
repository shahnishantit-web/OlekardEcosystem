<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;

final class QuoteStaleConfirmRequiredException extends \DomainException
{
    public function __construct(
        public readonly PaymentQuoteModel $newQuote,
        public readonly int               $previousTotalMinor,
        public readonly int               $newTotalMinor,
    ) {
        parent::__construct(
            "Quote has expired and the total changed from {$previousTotalMinor} to {$newTotalMinor}. Consumer must confirm the new amount."
        );
    }
}
