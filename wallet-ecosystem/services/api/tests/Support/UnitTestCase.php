<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class UnitTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Bootstrap a minimal Application so facade helpers (base_path, now,
        // DB::shouldReceive, Cache::shouldReceive, etc.) are available
        // without triggering any real database connections.
        new Application(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        \Mockery::close();
        Container::setInstance(null);
        parent::tearDown();
    }
}
