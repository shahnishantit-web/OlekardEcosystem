<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Redis\RedisManager;

/**
 * Base test case for Identity module feature and security tests.
 *
 * Extends the platform TestCase (DatabaseTransactions + PlatformConfigurationSeeder)
 * and adds two Identity-specific behaviours:
 *  1. Wraps both `platform` and `identity` DB connections in transactions per test.
 *  2. Injects a FakeRedisConnection so tests run without a live Redis server.
 */
abstract class IdentityTestCase extends TestCase
{
    protected FakeRedisConnection $identityRedis;

    /** @var string[] Connections wrapped in a transaction per test. */
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        app('cache')->forgetDriver('array');
        // The limiter is resolved during provider boot; replace only its test store.
        (new \ReflectionProperty(RateLimiter::class, 'cache'))->setValue(app(RateLimiter::class), app('cache')->store('array'));

        $this->identityRedis = new FakeRedisConnection;
        $this->injectFakeIdentityRedis($this->identityRedis);
    }

    private function injectFakeIdentityRedis(FakeRedisConnection $fake): void
    {
        /** @var RedisManager $manager */
        $manager = $this->app->make('redis');
        $reflection = new \ReflectionProperty($manager, 'connections');
        $reflection->setAccessible(true);

        $current = $reflection->getValue($manager);
        $current['identity'] = $fake;
        $reflection->setValue($manager, $current);
    }
}
