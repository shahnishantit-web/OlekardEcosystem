<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Providers;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Contracts\ProfileModuleContract;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Services\AccountStatusService;
use App\Modules\Profile\Domain\Services\ClosureGateService;
use App\Modules\Profile\Domain\Services\DataExportService;
use App\Modules\Profile\Domain\Services\PiiMaskingHelper;
use App\Modules\Profile\Domain\Services\ProfileMetrics;
use App\Modules\Profile\Domain\Services\ContactChangeService;
use App\Modules\Profile\Domain\Services\ProfileModuleService;
use App\Modules\Profile\Domain\Services\ProfilePhotoService;
use App\Modules\Profile\Domain\Services\ProfileService;
use App\Modules\Profile\Infrastructure\Http\Middleware\ProfileLatencyMiddleware;
use App\Modules\Profile\Infrastructure\Http\Middleware\ProfilePermissionMiddleware;
use App\Modules\Profile\Infrastructure\Listeners\CreateProfileOnUserRegisteredListener;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use App\Modules\Profile\Infrastructure\Storage\LocalDiskProfilePhotoStorage;
use App\Modules\Profile\Infrastructure\Stubs\StubFinancialObligationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ProfileModuleContract: exposes E02 status/completeness queries to other modules (T179)
        $this->app->singleton(ProfileModuleContract::class, ProfileModuleService::class);

        // Photo storage: LocalDiskProfilePhotoStorage for dev; production provider TBD-E02-001 (ADR-E02-002)
        $this->app->bind(ProfilePhotoStorageContract::class, LocalDiskProfilePhotoStorage::class);

        // Financial obligation gate: fail-safe stub until E06/E11 wire real implementations (ADR-E02-003)
        // Replaced by E06/E11 real implementations when available
        $this->app->bind(FinancialObligationContract::class, StubFinancialObligationService::class);

        // Repositories
        $this->app->bind(EloquentUserProfileRepository::class);
        $this->app->bind(EloquentAccountStatusHistoryRepository::class);
        $this->app->bind(EloquentDataExportRepository::class);

        // PiiMaskingHelper reuses E01/Platform Foundation PiiMasker (T180)
        $this->app->singleton(PiiMaskingHelper::class);

        // ProfileMetrics wraps MetricsCollectorInterface for E02 observability (T181)
        $this->app->singleton(ProfileMetrics::class);

        // ProfileService: personal info update — uses platform IdempotencyServiceInterface (T041)
        // IdempotencyServiceInterface singleton is registered in PlatformServiceProvider
        $this->app->singleton(ProfileService::class);

        // ProfilePhotoService: photo upload validation, storage, cleanup, event, audit (T050)
        $this->app->singleton(ProfilePhotoService::class);

        // ContactChangeService: email/mobile change initiation and OTP verification (T057, T058)
        $this->app->singleton(ContactChangeService::class);

        // ClosureGateService: checks FinancialObligationContract before closure transitions (T101)
        $this->app->singleton(ClosureGateService::class);

        // AccountStatusService: suspend, reactivate, requestClosure, cancelClosure (T085, T093)
        $this->app->singleton(AccountStatusService::class);

        // DataExportService: personal data export request + job dispatch (T113)
        $this->app->singleton(DataExportService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/profile.php'));

        // Named rate limiters sourced from ConfigurationService — never hard-coded here (C7; T177, T184)
        $config = $this->app->make(ConfigurationServiceInterface::class);

        RateLimiter::for('profile-user', static function (Request $r) use ($config): Limit {
            $limit = $config->get('profile.rate_limit.user_per_minute');
            return Limit::perMinute($limit ?? 60)->by($r->user()?->id ?? $r->ip());
        });

        RateLimiter::for('profile-photo', static function (Request $r) use ($config): Limit {
            $limit = $config->get('profile.rate_limit.photo_per_minute');
            return Limit::perMinute($limit ?? 20)->by($r->user()?->id ?? $r->ip());
        });

        // T184: admin-specific rate limiter.
        // When profile.rate_limit.admin_per_minute is null (TBD), apply Limit::none() — no rate limiting.
        // A numeric fallback is NOT permitted; the value is a product decision that remains TBD (constitution P2).
        RateLimiter::for('profile-admin', static function (Request $r) use ($config): Limit {
            $limit = $config->get('profile.rate_limit.admin_per_minute');
            return $limit !== null
                ? Limit::perMinute((int) $limit)->by($r->user()?->id ?? $r->ip())
                : Limit::none();
        });

        // T188: contact-change rate limiter with stricter per-hour limit (spec §9 security).
        // Uses profile.contact_change.otp_rate_limit_per_hour from platform.configuration_values.
        // Applies Limit::none() when TBD (null) — no hard-coded numeric fallback (constitution P2).
        RateLimiter::for('profile-contact-change', static function (Request $r) use ($config): Limit {
            $limit = $config->get('profile.contact_change.otp_rate_limit_per_hour');
            return $limit !== null
                ? Limit::perHour((int) $limit)->by($r->user()?->id ?? $r->ip())
                : Limit::none();
        });

        // T120: latency middleware for profile API observability (profile.api.latency histogram)
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('profile.latency', ProfileLatencyMiddleware::class);

        // T182: permission middleware for admin profile routes
        $router->aliasMiddleware('profile.permission', ProfilePermissionMiddleware::class);

        // T027: consume Identity.User.Registered outbox events dispatched by InProcessDriver
        Event::listen('Identity.User.Registered', CreateProfileOnUserRegisteredListener::class);
    }
}
