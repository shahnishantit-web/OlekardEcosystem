<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Cache;

use Illuminate\Support\Facades\Cache;

class IdempotencyCacheRepository
{
    private const KEY_PREFIX = 'idempotency:';

    public function find(string $actorId, string $key): ?array
    {
        $value = Cache::store('idempotency')->get($this->buildKey($actorId, $key));

        return $value !== null ? json_decode($value, true) : null;
    }

    public function store(string $actorId, string $key, array $response, int $ttl): void
    {
        Cache::store('idempotency')->put(
            $this->buildKey($actorId, $key),
            json_encode($response),
            $ttl,
        );
    }

    public function delete(string $actorId, string $key): void
    {
        Cache::store('idempotency')->forget($this->buildKey($actorId, $key));
    }

    private function buildKey(string $actorId, string $key): string
    {
        return self::KEY_PREFIX.$actorId.':'.$key;
    }
}
