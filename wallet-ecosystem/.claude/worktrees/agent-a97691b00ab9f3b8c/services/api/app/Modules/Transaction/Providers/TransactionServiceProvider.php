<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Providers;

use Illuminate\Support\ServiceProvider;

final class TransactionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stub: no bindings. Business logic deferred to E09.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/transaction.php'));
    }
}
