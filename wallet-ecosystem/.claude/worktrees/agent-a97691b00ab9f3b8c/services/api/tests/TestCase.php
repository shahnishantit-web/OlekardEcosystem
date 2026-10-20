<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Redirect the 'redis' cache store to in-memory array driver when
        // phpredis extension or predis library is unavailable in the test env.
        config(['cache.stores.redis' => ['driver' => 'array']]);
    }
}
