<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Providers;

use App\Modules\Kyc\Console\Commands\DetectExpiredKycCommand;
use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Contracts\KycDocumentStorageContract;
use App\Modules\Kyc\Domain\Contracts\KycVerificationProvider;
use App\Modules\Kyc\Infrastructure\Persistence\EloquentKycCaseRepository;
use App\Modules\Kyc\Infrastructure\Providers\NullKycVerificationProvider;
use App\Modules\Kyc\Infrastructure\Storage\LaravelDiskKycDocumentStorage;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class KycServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repository
        $this->app->bind(KycCaseRepositoryInterface::class, EloquentKycCaseRepository::class);

        // Storage
        $this->app->bind(KycDocumentStorageContract::class, LaravelDiskKycDocumentStorage::class);

        // KYC Verification Provider — config-driven factory per FR-E05-021
        // Provider MUST be swappable via config without code changes (TBD-E05-001)
        $this->app->bind(KycVerificationProvider::class, function ($app): KycVerificationProvider {
            /** @var ConfigurationServiceInterface $config */
            $config       = $app->make(ConfigurationServiceInterface::class);
            $providerName = $config->getOrDefault('kyc.provider', null);

            if ($providerName === null || $providerName === '') {
                return $app->make(NullKycVerificationProvider::class);
            }

            if (class_exists($providerName) && is_a($providerName, KycVerificationProvider::class, true)) {
                return $app->make($providerName);
            }

            return $app->make(NullKycVerificationProvider::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/kyc.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([DetectExpiredKycCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('kyc:detect-expired')->dailyAt('02:00');
        });
    }
}
