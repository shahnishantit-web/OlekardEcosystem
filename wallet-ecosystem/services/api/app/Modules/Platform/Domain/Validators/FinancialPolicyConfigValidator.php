<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Validators;

use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use Illuminate\Validation\ValidationException;

final class FinancialPolicyConfigValidator implements ConfigurationDomainValidatorInterface
{
    /** ISO 4217 3-letter currency codes (representative subset; extend as needed) */
    private const array VALID_ISO_4217 = [
        'USD', 'EUR', 'GBP', 'ZAR', 'BWP', 'KES', 'NGN', 'GHS', 'TZS', 'UGX',
        'ZMW', 'MWK', 'MOZ', 'RWF', 'ETB', 'XOF', 'XAF', 'MZN', 'ZWL', 'NAD',
    ];

    public function supportsDomain(ConfigurationDomain $domain): bool
    {
        return $domain === ConfigurationDomain::FinancialPolicy;
    }

    public function validate(string $key, mixed $value): void
    {
        if (str_starts_with($key, 'financial_policy.multi_currency.currency.')) {
            $code = substr($key, strlen('financial_policy.multi_currency.currency.'));
            if (! preg_match('/^[A-Z]{3}$/D', $code) || ! is_array($value) || ($value['code'] ?? null) !== $code || ! is_bool($value['is_active'] ?? null) || count($value) !== 2) {
                throw ValidationException::withMessages([$key => 'Currency status proposal requires its matching code and boolean is_active.']);
            }

            return;
        }
        $scalar = $this->extractScalar($value);

        if ($this->isLimitKey($key)) {
            $this->validatePositiveNumeric($key, $scalar);

            return;
        }

        if ($this->isRateKey($key)) {
            $this->validateRatePercent($key, $scalar);

            return;
        }

        if ($this->isCurrencyKey($key)) {
            $this->validateCurrencyCode($key, $scalar);

            return;
        }
    }

    private function extractScalar(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private function isLimitKey(string $key): bool
    {
        return str_contains($key, 'limit') || str_contains($key, 'max') || str_contains($key, 'min');
    }

    private function isRateKey(string $key): bool
    {
        return str_contains($key, 'rate') || str_contains($key, 'fee') || str_contains($key, 'commission');
    }

    private function isCurrencyKey(string $key): bool
    {
        return str_contains($key, 'currency');
    }

    private function validatePositiveNumeric(string $key, mixed $value): void
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => "Financial policy limit key '{$key}' must be a numeric value.",
            ]);
        }

        if ((float) $value <= 0) {
            throw ValidationException::withMessages([
                $key => "Financial policy limit key '{$key}' must be greater than zero.",
            ]);
        }
    }

    private function validateRatePercent(string $key, mixed $value): void
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $key => "Financial policy rate key '{$key}' must be a numeric value between 0 and 100.",
            ]);
        }

        $rate = (float) $value;
        if ($rate < 0 || $rate > 100) {
            throw ValidationException::withMessages([
                $key => "Financial policy rate key '{$key}' must be between 0 and 100 (inclusive).",
            ]);
        }
    }

    private function validateCurrencyCode(string $key, mixed $value): void
    {
        if (! is_string($value) || ! in_array(strtoupper($value), self::VALID_ISO_4217, true)) {
            throw ValidationException::withMessages([
                $key => "Financial policy currency key '{$key}' must be a valid ISO 4217 currency code.",
            ]);
        }
    }
}
