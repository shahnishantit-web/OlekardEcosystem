<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\TestCase;

/**
 * T029 — Verify that AppServiceProvider::boot() throws RuntimeException
 * when required secrets are absent. FR-011.
 */
final class SecretsBootCheckTest extends TestCase
{
    /** @test */
    public function boot_throws_when_app_key_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        // Override config to simulate missing APP_KEY
        config(['app.key' => '']);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();
    }

    /** @test */
    public function boot_throws_when_db_password_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB_PASSWORD/');

        // APP_KEY set but DB_PASSWORD absent
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        putenv('DB_PASSWORD=');

        $provider = new AppServiceProvider($this->app);
        $provider->boot();
    }

    /** @test */
    public function boot_does_not_throw_when_all_required_secrets_are_present(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        putenv('DB_PASSWORD=strongpassword');

        $provider = new AppServiceProvider($this->app);

        // Should not throw
        $provider->boot();
        $this->addToAssertionCount(1);
    }
}
