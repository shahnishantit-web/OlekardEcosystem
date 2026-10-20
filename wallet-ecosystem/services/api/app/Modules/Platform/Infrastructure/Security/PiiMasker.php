<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Security;

final class PiiMasker
{
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        [$local, $domain] = $parts;
        $domainParts = explode('.', $domain, 2);

        $maskedLocal  = substr($local, 0, 1).'***';
        $maskedDomain = substr($domainParts[0], 0, 1).'***'.($domainParts[1] ? '.'.$domainParts[1] : '');

        return $maskedLocal.'@'.$maskedDomain;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 4) {
            return '***';
        }

        $prefix = str_starts_with($phone, '+') ? '+' : '';
        $last4  = substr($digits, -4);

        return $prefix.'***'.$last4;
    }

    public static function maskNationalId(string $id): string
    {
        if (strlen($id) < 4) {
            return '****';
        }

        return '****'.substr($id, -4);
    }

    /** @param array<string, mixed> $data */
    public static function maskFields(array $data, array $sensitiveKeys = []): array
    {
        $defaultSensitive = ['password', 'pin', 'otp', 'secret', 'token', 'card_number', 'cvv', 'id_number', 'passport'];
        $allSensitive     = array_unique(array_merge($defaultSensitive, $sensitiveKeys));

        array_walk_recursive($data, function (mixed &$value, string $key) use ($allSensitive): void {
            foreach ($allSensitive as $field) {
                if (stripos($key, $field) !== false) {
                    $value = '***';
                    return;
                }
            }
        });

        return $data;
    }

    /** HMAC-SHA256 hash of an IP address for SEC-E04-006 audit storage (caller-supplied, never stored raw). */
    public static function hashIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /** HMAC-SHA256 hash of a national ID for SEC-E05-001 storage (plaintext MUST NOT be persisted). */
    public static function hashNationalId(string $id): string
    {
        if ($id === '') {
            return '';
        }
        return hash_hmac('sha256', $id, (string) config('app.key'));
    }
}
