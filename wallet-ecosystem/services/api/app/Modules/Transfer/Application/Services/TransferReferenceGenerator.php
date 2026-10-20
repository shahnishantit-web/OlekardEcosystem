<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use Illuminate\Support\Facades\DB;

final class TransferReferenceGenerator
{
    private const PREFIX      = 'TXF-';
    private const CODE_LENGTH = 10;
    private const MAX_RETRIES = 5;

    // Unambiguous alphabet: excludes I, O, 1, 0 to avoid visual confusion.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $code      = $this->randomCode();
            $reference = self::PREFIX . $code;

            $exists = DB::connection('platform')
                ->table('transfer.transfer_transactions')
                ->where('transfer_reference', $reference)
                ->exists();

            if (! $exists) {
                return $reference;
            }
        }

        throw new \RuntimeException('Failed to generate a unique transfer reference after ' . self::MAX_RETRIES . ' attempts.');
    }

    private function randomCode(): string
    {
        $alphabetLen = strlen(self::ALPHABET);
        $code        = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLen - 1)];
        }

        return $code;
    }
}
