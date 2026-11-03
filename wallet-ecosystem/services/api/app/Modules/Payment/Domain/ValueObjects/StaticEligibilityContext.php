<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/** Static provider eligibility context. It intentionally has no actor or wallet balance. */
final readonly class StaticEligibilityContext
{
    public function __construct(
        public string $purposeType,
        public string $currencyCode,
        public int $amountMinor,
        public string $organisationId,
        public string $countryCode,
        public string $moduleKey,
        public ?string $requiredCapability = null,
    ) {
        if ($purposeType === '' || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1 || $amountMinor < 1 || $organisationId === ''
            || preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1 || $moduleKey === '') {
            throw new \InvalidArgumentException('Static payment eligibility requires complete canonical context and a positive amount.');
        }
    }
}
