<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Providers;

use Illuminate\Support\ServiceProvider;

final class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub: no bindings. Business logic deferred to E05.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/wallet.php'));
    }
}
