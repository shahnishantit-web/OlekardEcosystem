<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Exceptions\BankReferenceGenerationException;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;

/**
 * Generates a unique, human-readable bank reference code used for inbound transfer matching.
 *
 * Format: OLK-{6 alphanumeric uppercase chars}  e.g. OLK-A3B9XZ
 * Resolves analyze finding M3 (plan.md omitted explicit uniqueness retry logic).
 *
 * Max 5 collision retries before throwing BankReferenceGenerationException.
 */
final class BankReferenceGenerator
{
    private const PREFIX      = 'OLK-';
    private const CODE_LENGTH = 6;
    private const MAX_RETRIES = 5;
    private const ALPHABET    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/1/0 to avoid confusion

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $code = self::PREFIX . $this->randomCode();

            $exists = FundingTransactionModel::where('bank_reference_code', $code)->exists();

            if (! $exists) {
                return $code;
            }
        }

        throw new BankReferenceGenerationException(self::MAX_RETRIES);
    }

    private function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $length   = strlen($alphabet);
        $code     = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $length - 1)];
        }

        return $code;
    }
}
