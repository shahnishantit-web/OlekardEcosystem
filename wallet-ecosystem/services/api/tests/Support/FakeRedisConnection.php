<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * In-memory Redis-like connection for testing environments without a Redis server.
 * Implements the subset of commands used by the Identity module.
 */
class FakeRedisConnection
{
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function set(string $key, mixed $value): string
    {
        $this->data[$key] = $value;
        return 'OK';
    }

    public function setex(string $key, int $seconds, mixed $value): string
    {
        $this->data[$key] = $value;
        return 'OK';
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function exists(string ...$keys): int
    {
        return count(array_filter($keys, fn ($k) => array_key_exists($k, $this->data)));
    }

    public function incr(string $key): int
    {
        $this->data[$key] = (int) ($this->data[$key] ?? 0) + 1;
        return $this->data[$key];
    }

    public function expire(string $key, int $seconds): int
    {
        return array_key_exists($key, $this->data) ? 1 : 0;
    }

    public function expireat(string $key, int $timestamp): int
    {
        return array_key_exists($key, $this->data) ? 1 : 0;
    }

    public function ttl(string $key): int
    {
        return array_key_exists($key, $this->data) ? 300 : -2;
    }

    public function flushdb(): bool
    {
        $this->data = [];
        return true;
    }

    public function keys(string $pattern): array
    {
        if ($pattern === '*') {
            return array_keys($this->data);
        }
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        return array_values(array_filter(array_keys($this->data), fn ($k) => preg_match($regex, $k)));
    }
}
