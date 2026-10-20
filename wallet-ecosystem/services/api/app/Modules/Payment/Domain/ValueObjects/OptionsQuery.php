<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class OptionsQuery
{
    public function __construct(
        public string $purposeType,
        public string $purposeReference,
        public string $currencyCode,
        public int    $amountMinor,
        public string $organisationId,
        public string $actorId,
        public string $countryCode = '',
        public string $moduleKey = '',
    ) {
    }
}
