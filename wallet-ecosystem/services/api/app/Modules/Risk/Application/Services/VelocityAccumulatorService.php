<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use Illuminate\Support\Facades\Redis;

final class VelocityAccumulatorService
{
    private const BUFFER_SECONDS = 300;

    public function record(string $key, string $amount, int $windowSeconds): void
    {
        $now    = microtime(true);
        $member = $now.'-'.uniqid('', true);
        $ttl    = $windowSeconds + self::BUFFER_SECONDS;

        $redis = Redis::connection();
        $redis->zadd($key, $now, $member.':'.$amount);
        $redis->expire($key, $ttl);
    }

    public function getCount(string $key, int $windowSeconds): int
    {
        $from = microtime(true) - $windowSeconds;

        return (int) Redis::connection()->zcount($key, (string) $from, '+inf');
    }

    public function getAmount(string $key, int $windowSeconds): string
    {
        $from    = microtime(true) - $windowSeconds;
        $members = Redis::connection()->zrangebyscore($key, (string) $from, '+inf');

        $total = '0';
        foreach ($members as $member) {
            $parts = explode(':', $member, 3);
            $amt   = $parts[1] ?? '0';
            $total = bcadd($total, $amt, 4);
        }

        return $total;
    }

    public function buildKey(string $customerId, string $txType, int $windowSeconds): string
    {
        return "velocity:{$customerId}:{$txType}:{$windowSeconds}";
    }
}
