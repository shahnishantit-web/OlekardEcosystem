<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Providers;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Application\Services\WalletHoldService;
use App\Modules\Wallet\Application\Services\WalletReferenceGenerator;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Infrastructure\Adapters\WalletModuleContractAdapter;
use App\Modules\Wallet\Infrastructure\Console\Commands\ExpireWalletHoldsCommand;
use App\Modules\Wallet\Infrastructure\Listeners\ProcessKycApprovedForWalletListener;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Modules\Wallet\Domain\Contracts\WalletReferenceLookupContract::class,
            \App\Modules\Wallet\Application\WalletReferenceLookup::class);
        // T059/T162: Bind real WalletModuleContractAdapter so cross-module consumers get live data.
        $this->app->bind(WalletModuleContract::class, WalletModuleContractAdapter::class);

        // WalletHoldService — internal Application service (not cross-module per research Decision 6)
        $this->app->bind(WalletHoldServiceContract::class, WalletHoldService::class);

        // Bind WalletReferenceGenerator with its uniqueness-checker closure.
        $this->app->bind(WalletReferenceGenerator::class, function () {
            return new WalletReferenceGenerator(
                fn (string $ref) => !WalletModel::where('wallet_reference', $ref)->exists()
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/wallet.php'));

        // T045: Listen for Kyc.Case.Approved outbox events to auto-provision wallets
        Event::listen('Kyc.Case.Approved', ProcessKycApprovedForWalletListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireWalletHoldsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $expireSchedule = config('wallet.expire_holds_schedule');
            if ($expireSchedule !== null) {
                $schedule->command('wallet:expire-holds')->cron($expireSchedule);
            }
        });
    }
}
