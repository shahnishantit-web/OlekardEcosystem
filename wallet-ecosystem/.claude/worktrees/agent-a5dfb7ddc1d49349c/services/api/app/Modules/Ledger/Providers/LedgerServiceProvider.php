<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub: no bindings. Business logic deferred to E07.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/ledger.php'));
    }
}
