<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\IdentityModule;
use App\Modules\Identity\Application\Services\AdminRoleService;
use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Identity\Domain\Contracts\AdminEmailVerificationMailContract;
use App\Modules\Identity\Domain\Contracts\AdminPasswordResetMailContract;
use App\Modules\Identity\Domain\Contracts\BiometricAssertionContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceBlockCheckServiceInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceSecurityEventRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\NewDeviceDetectedEvent;
use App\Modules\Identity\Domain\Events\SessionCreatedEvent;
use App\Modules\Identity\Domain\Repositories\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RolePermissionRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\UserRoleRepositoryInterface;
use App\Modules\Identity\Domain\Services\AdminEmailVerificationService;
use App\Modules\Identity\Domain\Services\ConfigurationAdminService;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Domain\Services\CredentialService;
use App\Modules\Identity\Domain\Services\DeviceBlockCheckService;
use App\Modules\Identity\Domain\Services\DeviceFingerprintService;
use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use App\Modules\Identity\Domain\Services\DeviceTrustService;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Identity\Domain\Services\JwksService;
use App\Modules\Identity\Domain\Services\MfaService;
use App\Modules\Identity\Domain\Services\OtpService;
use App\Modules\Identity\Domain\Services\PasswordRecoveryService;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Identity\Domain\Services\RefreshTokenService;
use App\Modules\Identity\Domain\Services\ServiceIdentityService;
use App\Modules\Identity\Domain\Services\SessionService;
use App\Modules\Identity\Domain\Services\StepUpService;
use App\Modules\Identity\Domain\Services\TokenService;
use App\Modules\Identity\Infrastructure\Http\Middleware\AuthLatencyMiddleware;
use App\Modules\Identity\Infrastructure\Http\Middleware\IdentityAuthMiddleware;
use App\Modules\Identity\Infrastructure\Http\Middleware\IdentityOptionalAuthMiddleware;
use App\Modules\Identity\Infrastructure\Http\Middleware\RequireActorTypeMiddleware;
use App\Modules\Identity\Infrastructure\Http\Middleware\RequirePermissionMiddleware;
use App\Modules\Identity\Infrastructure\Jwks\LocalJwksProvider;
use App\Modules\Identity\Infrastructure\Listeners\HandleNewDeviceDetectedListener;
use App\Modules\Identity\Infrastructure\Listeners\HandleSessionCreatedListener;
use App\Modules\Identity\Infrastructure\Repositories\EloquentCredentialRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceSecurityEventRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceTrustHistoryRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentOtpChallengeRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentPermissionRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentRolePermissionRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentRoleRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentSessionRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentUserRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentUserRoleRepository;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Identity\Infrastructure\Stubs\StubAdminEmailVerificationMailer;
use App\Modules\Identity\Infrastructure\Stubs\StubAdminPasswordResetMailer;
use App\Modules\Identity\Infrastructure\Stubs\StubBiometricAssertionProvider;
use App\Modules\Identity\Infrastructure\Stubs\StubOtpDeliveryProvider;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Infrastructure\Security\EnvSecretProvider;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Modules\Identity\Domain\Contracts\OrganisationMembershipAuthority::class, \App\Modules\Identity\Application\Services\ReviewedOrganisationMembershipService::class);
        // Cross-module contract: concrete IdentityModule wired in Phase 14 (T140)
        $this->app->singleton(IdentityModuleContract::class, IdentityModule::class);
        $this->app->singleton(ActorStepUpVerifierContract::class, IdentityModule::class);

        // OTP delivery: StubOtpDeliveryProvider in non-production environments
        // Swap to a concrete provider when auth.otp.provider TBD is resolved
        $this->app->bind(OtpDeliveryContract::class, StubOtpDeliveryProvider::class);

        // Biometric assertion: TBD-004 abstraction boundary — do NOT choose an SDK here
        $this->app->bind(BiometricAssertionContract::class, StubBiometricAssertionProvider::class);

        // JWKS: LocalJwksProvider reads key material from IDENTITY_JWT_PRIVATE_KEY env secret
        $this->app->singleton(JwksProviderContract::class, LocalJwksProvider::class);

        // Repositories
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(CredentialRepositoryInterface::class, EloquentCredentialRepository::class);
        $this->app->bind(SessionRepositoryInterface::class, EloquentSessionRepository::class);
        $this->app->bind(DeviceRepositoryInterface::class, EloquentDeviceRepository::class);
        $this->app->bind(OtpChallengeRepositoryInterface::class, EloquentOtpChallengeRepository::class);

        // Security infrastructure
        $this->app->bind(SecretProviderInterface::class, EnvSecretProvider::class);
        $this->app->singleton(IpHasher::class, fn ($app) => new IpHasher($app->make(SecretProviderInterface::class)));

        // Domain services
        $this->app->singleton(
            ConfigurationSecurityService::class,
            fn ($app) => new ConfigurationSecurityService($app->make(ConfigurationServiceInterface::class)),
        );

        // TokenService: real ES256 implementation (Phase 7 T074)
        $this->app->bind(TokenServiceContract::class, TokenService::class);

        // JwksService: builds JWK Set, caches in Redis identity:jwks
        $this->app->singleton(JwksService::class);

        // Phase 8: Session & Refresh Token Lifecycle
        $this->app->singleton(RefreshTokenService::class);
        $this->app->singleton(SessionService::class);

        // Phase 11: PIN & Credential Management
        $this->app->singleton(CredentialService::class);

        // Phase 12: MFA (TOTP + FIDO2)
        $this->app->singleton(MfaService::class);

        // Phase 13: Step-Up Authentication
        $this->app->singleton(StepUpService::class);

        // Phase 14: Service Identity
        $this->app->singleton(ServiceIdentityService::class);

        // Phase 15: Configuration Admin API
        $this->app->singleton(ConfigurationAdminService::class);

        // OtpServiceContract: cross-module interface binding (E02 ADR-E02-001)
        $this->app->singleton(OtpServiceContract::class, OtpService::class);

        // Phase 20: Observability & Metrics
        $this->app->singleton(
            IdentityMetrics::class,
            fn ($app) => new IdentityMetrics($app->make(MetricsCollectorInterface::class)),
        );

        // E04: Roles, Permissions & Authorization
        $this->app->bind(RoleRepositoryInterface::class, EloquentRoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, EloquentPermissionRepository::class);
        $this->app->bind(UserRoleRepositoryInterface::class, EloquentUserRoleRepository::class);
        $this->app->bind(RolePermissionRepositoryInterface::class, EloquentRolePermissionRepository::class);
        $this->app->singleton(PermissionResolverService::class);
        $this->app->singleton(AdminRoleService::class);
        $this->app->singleton(AdminUserService::class);

        // E03: Device & Session Management
        $this->app->bind(DeviceBlockCheckServiceInterface::class, DeviceBlockCheckService::class);
        $this->app->bind(DeviceTrustHistoryRepositoryInterface::class, EloquentDeviceTrustHistoryRepository::class);
        $this->app->bind(DeviceSecurityEventRepositoryInterface::class, EloquentDeviceSecurityEventRepository::class);
        $this->app->singleton(DeviceFingerprintService::class);
        $this->app->singleton(DeviceTrustService::class);
        $this->app->singleton(DeviceSecurityService::class);
        $this->app->singleton(DeviceBlockCheckService::class);

        // ADM-P1-001: Admin Password Recovery
        $this->app->bind(
            AdminPasswordResetMailContract::class,
            StubAdminPasswordResetMailer::class,
        );
        $this->app->singleton(PasswordRecoveryService::class);

        // ADM-P1-001: Admin Email Verification
        $this->app->bind(
            AdminEmailVerificationMailContract::class,
            StubAdminEmailVerificationMailer::class,
        );
        $this->app->singleton(AdminEmailVerificationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/identity.php'));

        // T131: Register E03 event listeners
        $this->app['events']->listen(NewDeviceDetectedEvent::class, HandleNewDeviceDetectedListener::class);
        $this->app['events']->listen(SessionCreatedEvent::class, HandleSessionCreatedListener::class);

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('identity.auth', IdentityAuthMiddleware::class);
        $router->aliasMiddleware('identity.auth.optional', IdentityOptionalAuthMiddleware::class);
        $router->aliasMiddleware('identity.latency', AuthLatencyMiddleware::class);
        $router->aliasMiddleware('identity.require-permission', RequirePermissionMiddleware::class);
        $router->aliasMiddleware('identity.require-actor-type', RequireActorTypeMiddleware::class);

        // Named rate limiters for identity throttle aliases (spec §24.6; T237)
        // Limits are sourced from ConfigurationSecurityService — never hard-coded here.
        // Production values must be set via platform.configuration_values.
        $secConfig = $this->app->make(ConfigurationSecurityService::class);
        RateLimiter::for('identity-public', fn (Request $r) => Limit::perMinute($secConfig->getRateLimitPublic()));
        RateLimiter::for('identity-refresh', fn (Request $r) => Limit::perMinute($secConfig->getRateLimitRefresh()));
        RateLimiter::for('identity-auth', fn (Request $r) => Limit::perMinute($secConfig->getRateLimitAuth()));
        RateLimiter::for('identity-admin', fn (Request $r) => Limit::perMinute($secConfig->getRateLimitAdmin()));
    }
}
