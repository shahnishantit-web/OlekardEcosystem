<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

final readonly class EventPricingRequest
{
    /** @param list<EventPricingLine> $lines */
    public function __construct(
        public string $eventId,
        public string $organisationId,
        public string $venueCountryCode,
        public string $currencyCode,
        public int $currencyPrecision,
        public array $lines,
    ) {
        if ($eventId === '' || $organisationId === '' || preg_match('/^[A-Z]{2}$/D', $venueCountryCode) !== 1
            || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1 || $currencyPrecision < 0 || $lines === []) {
            throw new \InvalidArgumentException('Pricing needs an approved physical event context and at least one line.');
        }
        foreach ($lines as $line) {
            if (! $line instanceof EventPricingLine) {
                throw new \InvalidArgumentException('Pricing lines must use the typed Events pricing value object.');
            }
        }
    }

    public function subtotalMinor(): int
    {
        return array_sum(array_map(static fn (EventPricingLine $line): int => $line->quantity * $line->unitPriceMinor, $this->lines));
    }
}
