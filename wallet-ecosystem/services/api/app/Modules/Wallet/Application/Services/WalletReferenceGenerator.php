<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Modules\Wallet\Domain\Exceptions\WalletReferenceCollisionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WalletReferenceGenerator
{
    private const MAX_ATTEMPTS = 5;
    private const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function __construct(
        private readonly \Closure $uniquenessChecker,
    ) {}

    /**
     * Generates a unique wallet reference.
     * Format governed by TBD-E06-007 (wallet.reference_format config key).
     * Falls back to interim default: WLT-{YYYYMMDD}-{8 random uppercase alphanum}.
     */
    public function generate(): string
    {
        $format = config('wallet.reference_format');

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $reference = $format !== null
                ? $this->generateFromFormat($format)
                : $this->generateDefault();

            if (($this->uniquenessChecker)($reference)) {
                return $reference;
            }

            Log::warning('wallet.reference.collision', [
                'attempt'   => $attempt,
                'reference' => $reference,
            ]);
        }

        throw new WalletReferenceCollisionException(
            "Failed to generate a unique wallet reference after " . self::MAX_ATTEMPTS . " attempts."
        );
    }

    private function generateDefault(): string
    {
        $date = now()->format('Ymd');
        $rand = '';
        for ($i = 0; $i < 8; $i++) {
            $rand .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }

        return "WLT-{$date}-{$rand}";
    }

    private function generateFromFormat(string $format): string
    {
        // Placeholder: when TBD-E06-007 resolves, implement custom format parsing here.
        return $this->generateDefault();
    }
}
