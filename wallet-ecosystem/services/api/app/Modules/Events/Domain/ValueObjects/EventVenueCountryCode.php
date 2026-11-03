<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

final readonly class EventVenueCountryCode
{
    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $code = strtoupper(trim($value));
        if (! self::isValid($code)) {
            throw new \InvalidArgumentException('Venue country must be a valid ISO 3166-1 alpha-2 country code.');
        }

        return new self($code);
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1 || ! class_exists(\Locale::class)) {
            return false;
        }

        $regionName = \Locale::getDisplayRegion('und-'.$value, 'en');

        return is_string($regionName) && $regionName !== '' && $regionName !== $value && $regionName !== 'Unknown Region';
    }
}
