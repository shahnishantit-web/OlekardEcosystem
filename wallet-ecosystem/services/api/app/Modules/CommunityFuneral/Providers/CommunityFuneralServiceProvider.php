<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Providers;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Application\FuneralObligationResolver;
use App\Modules\CommunityFuneral\Application\FuneralPaymentConfirmedListener;
use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\CommunityFuneral\Infrastructure\IdentityOrganisationMembershipVerifier;
use App\Modules\CommunityFuneral\Infrastructure\Console\RetryFuneralPrivateNotices;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use Illuminate\Support\Facades\Event;
use App\Modules\CommunityFuneral\Domain\Contracts\FuneralCollectionRepositoryInterface;
use App\Modules\CommunityFuneral\Infrastructure\Repositories\FuneralCollectionRepository;
use Illuminate\Support\ServiceProvider;

final class CommunityFuneralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganisationMembershipVerifier::class, IdentityOrganisationMembershipVerifier::class);
        $this->app->bind(FuneralCollectionRepositoryInterface::class, FuneralCollectionRepository::class);
        $this->app->singleton(FuneralAssistanceService::class);
        $this->app->singleton(FuneralObligationResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/community-funeral.php'));
        if ($this->app->runningInConsole()) {
            $this->commands([RetryFuneralPrivateNotices::class]);
        }
        $this->app->make(BusinessPurposeResolverRegistry::class)
            ->register('funeral_contribution', FuneralObligationResolver::class);
        Event::listen('payment.confirmed', FuneralPaymentConfirmedListener::class);
    }
}
