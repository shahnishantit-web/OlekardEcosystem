<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Exceptions\InsufficientFingerprintEntropyException;

final class DeviceFingerprintService
{
    /**
     * Compute a SHA-256 hex fingerprint from device attributes.
     * Removes null values, sorts keys alphabetically, JSON-encodes, then hashes.
     */
    public function computeHash(array $attributes, string $platform): string
    {
        // Normalize: remove nulls
        $normalized = array_filter($attributes, static fn ($v) => $v !== null);

        // Sort keys for determinism
        ksort($normalized);

        // Include platform so ios and android fingerprints cannot collide for same attributes
        $canonical = json_encode(
            ['platform' => $platform, 'attributes' => $normalized],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', (string) $canonical);
    }

    /**
     * Validate that the fingerprint attributes contain sufficient entropy.
     * Shannon entropy is computed across all string values concatenated.
     *
     * @throws InsufficientFingerprintEntropyException
     */
    public function validateEntropy(array $attributes, string $platform, int $minBits): void
    {
        // Collect all string values
        $allChars = '';
        foreach ($attributes as $value) {
            if (is_string($value)) {
                $allChars .= $value;
            } elseif (is_int($value) || is_float($value)) {
                $allChars .= (string) $value;
            }
        }

        $length = strlen($allChars);

        if ($length === 0) {
            throw InsufficientFingerprintEntropyException::make();
        }

        // Character frequency distribution
        $freq = array_count_values(str_split($allChars));

        // Shannon entropy in bits: -sum(p * log2(p)) * length
        $entropy = 0.0;
        foreach ($freq as $count) {
            $p        = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        // Total bits of entropy = entropy-per-char * total chars
        $totalBits = $entropy * $length;

        if ($totalBits < $minBits) {
            throw InsufficientFingerprintEntropyException::make();
        }
    }

    /**
     * Detect common emulator/simulator indicators in device attributes.
     */
    public function detectEmulatorIndicators(array $attributes, string $platform): bool
    {
        return match (strtolower($platform)) {
            'android' => $this->detectAndroidEmulator($attributes),
            'ios'     => $this->detectIosSimulator($attributes),
            default   => false,
        };
    }

    private function detectAndroidEmulator(array $attributes): bool
    {
        $androidId     = strtolower((string) ($attributes['android_id'] ?? ''));
        $hardwareModel = strtolower((string) ($attributes['hardware_model'] ?? ''));

        if ($androidId === '0000000000000000') {
            return true;
        }

        foreach (['sdk', 'emulator', 'generic'] as $indicator) {
            if (str_contains($hardwareModel, $indicator)) {
                return true;
            }
        }

        return false;
    }

    private function detectIosSimulator(array $attributes): bool
    {
        $hardwareModel = strtolower((string) ($attributes['hardware_model'] ?? ''));
        $idfv          = (string) ($attributes['idfv'] ?? '');

        if (in_array($hardwareModel, ['x86_64', 'i386'], true)) {
            return true;
        }

        if ($idfv === '00000000-0000-0000-0000-000000000000') {
            return true;
        }

        return false;
    }
}
