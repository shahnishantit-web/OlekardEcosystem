<?php

declare(strict_types=1);

namespace App\Modules\Qr\Providers;

use App\Modules\Qr\Application\Jobs\ReleaseExpiredQrReservationsJob;
use App\Modules\Qr\Application\Services\QrCodeService;
use App\Modules\Qr\Application\Services\QrPayloadSigner;
use App\Modules\Qr\Application\Services\QrPaymentService;
use App\Modules\Qr\Application\Services\QrReceiptService;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Infrastructure\Services\WalletOwnerMerchantReadService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class QrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MerchantReadServiceInterface::class, WalletOwnerMerchantReadService::class);

        $this->app->singleton(QrPayloadSigner::class);
        $this->app->singleton(QrCodeService::class);
        $this->app->singleton(QrPaymentService::class);
        $this->app->singleton(QrReceiptService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/qr.php'));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new ReleaseExpiredQrReservationsJob())
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
