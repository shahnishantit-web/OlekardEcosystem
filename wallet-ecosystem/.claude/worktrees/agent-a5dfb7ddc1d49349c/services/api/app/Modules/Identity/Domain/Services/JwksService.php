<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Illuminate\Support\Facades\Redis;

/**
 * Builds the JWK Set document and caches it in Redis.
 * Private key material (field `d`) MUST NEVER appear in output — enforced by JwkPublicKey::toArray().
 */
final class JwksService
{
    private const DEFAULT_CACHE_TTL = 300;

    public function __construct(
        private readonly JwksProviderContract $jwks,
    ) {}

    /**
     * Return the JWK Set as a plain array suitable for JSON serialisation.
     * Result is cached in Redis `identity:jwks` with a 5-minute TTL.
     */
    public function getPublicKeySet(): array
    {
        $redis    = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $cached   = $redis->get(IdentityRedisKeys::JWKS);

        if ($cached !== null && $cached !== false) {
            return json_decode($cached, true);
        }

        $keys = array_map(
            static fn ($key) => $key->toArray(),
            $this->jwks->getVerificationKeys(),
        );

        $payload = ['keys' => $keys];
        $redis->setex(IdentityRedisKeys::JWKS, self::DEFAULT_CACHE_TTL, json_encode($payload));

        return $payload;
    }

    public function invalidateCache(): void
    {
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->del(IdentityRedisKeys::JWKS);
    }
}
