<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final class CsvFormulaSanitiser
{
    private const INJECTION_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Prevent CSV formula injection (OWASP CWE-1236).
     *
     * Prefixes values starting with injection characters with a single quote,
     * forcing spreadsheet software to treat them as text literals.
     */
    public static function sanitise(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::INJECTION_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'" . $value;
            }
        }

        return $value;
    }
}
