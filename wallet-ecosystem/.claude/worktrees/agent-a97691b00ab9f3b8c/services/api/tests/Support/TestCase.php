<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\PlatformConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Redirect cache stores to in-memory array when phpredis/predis is
        // unavailable and when the 'idempotency' store is not provisioned.
        config([
            'cache.stores.redis'       => ['driver' => 'array'],
            'cache.stores.idempotency' => ['driver' => 'array'],
        ]);
        app('cache')->forgetDriver('redis');
        app('cache')->forgetDriver('idempotency');

        $this->seed(PlatformConfigurationSeeder::class);

        // Inject test correlation context
        $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \Illuminate\Support\Facades\Log::withContext(['correlation_id' => $correlationId]);
    }
}
