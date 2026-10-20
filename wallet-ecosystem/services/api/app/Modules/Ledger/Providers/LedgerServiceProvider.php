<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Providers;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Infrastructure\Console\Commands\CheckLedgerBalanceCommand;
use Illuminate\Support\ServiceProvider;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LedgerModuleContract::class, LedgerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/ledger.php'));
        $this->commands([CheckLedgerBalanceCommand::class]);
    }
}
