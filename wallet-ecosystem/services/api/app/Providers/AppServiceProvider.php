<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->assertRequiredSecrets();
    }

    private function assertRequiredSecrets(): void
    {
        $required = [
            'APP_KEY'     => config('app.key'),
            'DB_PASSWORD' => env('DB_PASSWORD'),
        ];

        foreach ($required as $name => $value) {
            if (empty($value)) {
                throw new RuntimeException("Required secret [{$name}] is not set. Refusing to boot.");
            }
        }
    }
}
