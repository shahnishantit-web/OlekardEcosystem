<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Services\LockoutService;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;

function makeLockoutConfig(array $values = []): ConfigurationSecurityService
{
    $configService = new class($values) implements ConfigurationServiceInterface {
        public function __construct(private array $v) {}
        public function get(string $key, bool $requireValue = false): mixed { return $this->v[$key] ?? null; }
        public function getOrDefault(string $key, mixed $default): mixed { return $this->v[$key] ?? $default; }
        public function isConfigured(string $key): bool { return isset($this->v[$key]); }
        public function invalidate(string $key): void {}
    };
    return new ConfigurationSecurityService($configService);
}

function makeLockoutService(array $configValues = [], array &$repositoryUpdates = []): LockoutService
{
    $users = new class($repositoryUpdates) implements UserRepositoryInterface {
        public function __construct(private array &$updates) {}
        public function findByMobile(string $mobileNumber): ?User { return null; }
        public function findByEmail(string $email): ?User { return null; }
        public function findById(string $id): ?User { return null; }
        public function create(array $attributes): User { return (new User())->forceFill($attributes); }
        public function updateStatus(string $id, string $status, array $extra = []): void { $this->updates['status'] = $status; }
        public function incrementFailedAttempts(string $id): void { $this->updates[] = 'increment'; }
        public function resetFailedAttempts(string $id): void { $this->updates['reset'] = true; }
        public function incrementLockoutEpisode(string $id): void { $this->updates['episode'] = true; }
        public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void { $this->updates['locked_at'] = $lockedAt; }
        public function updateLastLogin(string $id): void {}
        public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void {}
    };

    $events = new class implements EventPublisherInterface {
        public function publish(\App\Modules\Platform\Domain\Contracts\IntegrationEventInterface $e): void {}
        public function publishAll(array $events): void {}
    };

    $audit = new class implements AuditServiceInterface {
        public function record(\App\Modules\Platform\Domain\ValueObjects\AuditEventData $e): void {}
    };

    $nullCollector = new class implements \App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface {
        public function increment(string $name, array $labels = []): void {}
        public function gauge(string $name, float $value, array $labels = []): void {}
        public function histogram(string $name, float $value, array $labels = []): void {}
    };
    $metrics = new \App\Modules\Identity\Domain\Services\IdentityMetrics($nullCollector);

    return new LockoutService($users, makeLockoutConfig($configValues), $events, $audit, $metrics);
}

function makeUserObject(array $data): \App\Modules\Identity\Infrastructure\Models\User
{
    return (new \App\Modules\Identity\Infrastructure\Models\User())->forceFill([
        'id'                    => $data['id'] ?? 'test-user-id',
        'actor_type'            => $data['actor_type'] ?? 'CUSTOMER',
        'locked_at'             => $data['locked_at'] ?? null,
        'lockout_episode_count' => $data['lockout_episode_count'] ?? null,
        'failed_attempt_count'  => $data['failed_attempt_count'] ?? 0,
    ]);
}

describe('LockoutService unit', function (): void {

    it('does not throw when user has no locked_at', function (): void {
        $service = makeLockoutService();
        $user    = makeUserObject(['locked_at' => null]);

        expect(fn () => $service->assertNotLocked($user, 'corr', 'ip'))->not->toThrow(AccountLockedException::class);
    });

    it('throws AccountLockedException when lock is still active', function (): void {
        $service = makeLockoutService(['auth.lockout.initial_duration' => '900', 'auth.lockout.progressive_enabled' => 'true']);
        $user    = makeUserObject(['locked_at' => now()->subSeconds(60)->toDateTimeString(), 'lockout_episode_count' => 1]);

        expect(fn () => $service->assertNotLocked($user, 'corr', 'ip'))->toThrow(AccountLockedException::class);
    });

    it('computes progressive lockout duration: episode 1 = initial, episode 2 = 2x, episode 3 = 4x', function (): void {
        $service = makeLockoutService(['auth.lockout.initial_duration' => '900', 'auth.lockout.progressive_enabled' => 'true']);
        $method  = new ReflectionMethod($service, 'computeLockoutDuration');
        $method->setAccessible(true);

        expect($method->invoke($service, 1))->toBe(900);
        expect($method->invoke($service, 2))->toBe(1800);
        expect($method->invoke($service, 3))->toBe(3600);
    });

    it('uses flat duration when progressive is disabled', function (): void {
        $service = makeLockoutService(['auth.lockout.initial_duration' => '900', 'auth.lockout.progressive_enabled' => 'false']);
        $method  = new ReflectionMethod($service, 'computeLockoutDuration');
        $method->setAccessible(true);

        expect($method->invoke($service, 1))->toBe(900);
        expect($method->invoke($service, 3))->toBe(900);
    });

    it('marks reset on recordSuccess', function (): void {
        $updates = [];
        $service = makeLockoutService([], $updates);
        $user    = makeUserObject([]);

        $service->recordSuccess($user);

        expect($updates['reset'])->toBeTrue();
    });

})->group('identity', 'lockout', 'unit');
