<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Security;

use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;

/**
 * Computes HMAC-SHA256(platform_secret, ip_address) for audit records.
 * Raw IP MUST NOT be stored; only the hash is persisted.
 * Secret loaded from env via SecretProviderInterface — never from platform.configuration_values.
 */
final class IpHasher
{
    private const SECRET_KEY = 'IDENTITY_IP_HMAC_SECRET';

    private string $secret;

    public function __construct(SecretProviderInterface $secrets)
    {
        $this->secret = $secrets->get(self::SECRET_KEY);
    }

    public function hash(string $ipAddress): string
    {
        return hash_hmac('sha256', $ipAddress, $this->secret);
    }
}
