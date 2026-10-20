<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Validators;

use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GeographicConfigValidator implements ConfigurationDomainValidatorInterface
{
    /** ISO 4217 currency codes */
    private const array VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'ZAR', 'BWP', 'KES', 'NGN', 'GHS', 'TZS', 'UGX',
        'ZMW', 'MWK', 'MOZ', 'RWF', 'ETB', 'XOF', 'XAF', 'MZN', 'ZWL', 'NAD',
        'CNY', 'JPY', 'INR', 'BRL', 'MXN', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK',
    ];

    /** ISO 3166-1 alpha-2 country codes (representative subset) */
    private const array VALID_COUNTRIES = [
        'ZA', 'BW', 'KE', 'NG', 'GH', 'TZ', 'UG', 'ZM', 'MW', 'MZ', 'RW',
        'ET', 'ZW', 'NA', 'US', 'GB', 'DE', 'FR', 'IT', 'ES', 'JP', 'CN',
        'IN', 'BR', 'MX', 'CA', 'AU', 'SN', 'CI', 'CM',
    ];

    public function supportsDomain(ConfigurationDomain $domain): bool
    {
        return $domain === ConfigurationDomain::Geographic;
    }

    public function validate(string $key, mixed $value): void
    {
        $scalar = $this->extractScalar($value);

        if ($this->isCurrencyKey($key)) {
            $this->validateCurrencyCode($key, $scalar);
            return;
        }

        if ($this->isCountryKey($key)) {
            $this->validateCountryCode($key, $scalar);
            return;
        }

        if ($this->isSupportedCurrenciesKey($key)) {
            $this->validateSupportedCurrencies($key, $scalar);
            return;
        }

        if ($this->isSupportedCountriesKey($key)) {
            $this->validateSupportedCountries($key, $scalar);
            return;
        }
    }

    private function extractScalar(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value) && count($value) === 1) {
            return $value['value'];
        }

        return $value;
    }

    private function isCurrencyKey(string $key): bool
    {
        return str_contains($key, '.currency') && !str_contains($key, 'currencies');
    }

    private function isCountryKey(string $key): bool
    {
        return str_contains($key, '.country') && !str_contains($key, 'countries');
    }

    private function isSupportedCurrenciesKey(string $key): bool
    {
        return str_contains($key, 'currencies');
    }

    private function isSupportedCountriesKey(string $key): bool
    {
        return str_contains($key, 'countries');
    }

    private function validateCurrencyCode(string $key, mixed $value): void
    {
        if (!is_string($value) || !in_array(strtoupper($value), self::VALID_CURRENCIES, true)) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' must be a valid ISO 4217 currency code.",
            ]);
        }
    }

    private function validateCountryCode(string $key, mixed $value): void
    {
        if (!is_string($value) || !in_array(strtoupper($value), self::VALID_COUNTRIES, true)) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' must be a valid ISO 3166-1 alpha-2 country code.",
            ]);
        }
    }

    private function validateSupportedCurrencies(string $key, mixed $value): void
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' must be an array of ISO 4217 currency codes.",
            ]);
        }

        foreach ($value as $code) {
            if (!is_string($code) || !in_array(strtoupper($code), self::VALID_CURRENCIES, true)) {
                throw ValidationException::withMessages([
                    $key => "Geographic key '{$key}' contains invalid ISO 4217 currency code '{$code}'.",
                ]);
            }
        }

        // Duplicate detection
        $upper = array_map('strtoupper', $value);
        if (count($upper) !== count(array_unique($upper))) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' contains duplicate currency codes.",
            ]);
        }
    }

    private function validateSupportedCountries(string $key, mixed $value): void
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' must be an array of ISO 3166-1 alpha-2 country codes.",
            ]);
        }

        foreach ($value as $code) {
            if (!is_string($code) || !in_array(strtoupper($code), self::VALID_COUNTRIES, true)) {
                throw ValidationException::withMessages([
                    $key => "Geographic key '{$key}' contains invalid ISO 3166-1 alpha-2 country code '{$code}'.",
                ]);
            }
        }

        // Duplicate detection
        $upper = array_map('strtoupper', $value);
        if (count($upper) !== count(array_unique($upper))) {
            throw ValidationException::withMessages([
                $key => "Geographic key '{$key}' contains duplicate country codes.",
            ]);
        }
    }
}
