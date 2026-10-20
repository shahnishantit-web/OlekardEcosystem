<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Providers;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxQuoteRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateProviderInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxTransactionRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\SameCurrencyTransferRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Providers\UnavailableFxRateProvider;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentCurrencyRepository;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentCurrencyWalletRepository;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentFxQuoteRepository;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentFxRateRepository;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentFxTransactionRepository;
use App\Modules\MultiCurrency\Infrastructure\Repositories\EloquentSameCurrencyTransferRepository;
use Illuminate\Support\ServiceProvider;

final class MultiCurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FxRateProviderInterface::class, UnavailableFxRateProvider::class);
        $this->app->bind(CurrencyRepositoryInterface::class, EloquentCurrencyRepository::class);
        $this->app->bind(CurrencyWalletRepositoryInterface::class, EloquentCurrencyWalletRepository::class);
        $this->app->bind(FxRateRepositoryInterface::class, EloquentFxRateRepository::class);
        $this->app->bind(FxQuoteRepositoryInterface::class, EloquentFxQuoteRepository::class);
        $this->app->bind(FxTransactionRepositoryInterface::class, EloquentFxTransactionRepository::class);
        $this->app->bind(SameCurrencyTransferRepositoryInterface::class, EloquentSameCurrencyTransferRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/multi-currency.php'));
        $this->loadRoutesFrom(base_path('routes/modules/admin-multi-currency.php'));
    }
}
