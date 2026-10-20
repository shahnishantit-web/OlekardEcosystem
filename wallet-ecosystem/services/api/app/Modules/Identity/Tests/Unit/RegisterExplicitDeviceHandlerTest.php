<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Commands\RegisterExplicitDeviceCommand;
use App\Modules\Identity\Application\Handlers\RegisterExplicitDeviceHandler;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceRegisteredEvent;
use App\Modules\Identity\Domain\Exceptions\DeviceLimitExceededException;
use App\Modules\Identity\Domain\Services\DeviceService;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Identity\Infrastructure\Models\DeviceTrustHistory;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\Queue;

function makeMetrics(): IdentityMetrics
{
    $collector = new class implements MetricsCollectorInterface {
        public function increment(string $metric, array $labels = []): void {}
        public function histogram(string $metric, float $value, array $labels = []): void {}
        public function gauge(string $metric, float $value, array $labels = []): void {}
    };
    return new IdentityMetrics($collector);
}

function makeStubDeviceRepo(array $state = [], int $count = 0): DeviceRepositoryInterface
{
    return new class($state, $count) implements DeviceRepositoryInterface {
        public ?Device $lastCreated = null;

        public function __construct(private array $state, private int $count) {}

        public function findById(string $id): ?Device { return null; }

        public function findByUserAndFingerprint(string $userId, string $hash): ?Device
        {
            return $this->state['existing'] ?? null;
        }

        public function create(array $a): Device
        {
            $d = (new Device())->forceFill(
                array_merge($a, ['id' => (string) \Ramsey\Uuid\Uuid::uuid7(), 'created_at' => now(), 'updated_at' => now()])
            );
            $this->lastCreated = $d;
            return $d;
        }

        public function updateStatus(string $id, string $status, array $extra = []): void {}
        public function listForUser(string $userId): array { return []; }
        public function updateLastSeen(string $id): void {}
        public function findByFingerprint(string $hash): array { return []; }
        public function countForUser(string $userId): int { return $this->count; }

        public function listForUserPaginated(string $userId, int $page, int $perPage, ?string $status = null, ?string $platform = null): array
        {
            return ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1]];
        }

        public function blockDevice(string $id, string $blockedAt, ?string $blockedReason): void {}
        public function unblockDevice(string $id): void {}
        public function updateFingerprint(string $deviceId, string $newHash): void {}
    };
}

function makeStubTrustHistoryRepo(): DeviceTrustHistoryRepositoryInterface
{
    return new class implements DeviceTrustHistoryRepositoryInterface {
        public function append(array $a): DeviceTrustHistory
        {
            return (new DeviceTrustHistory())->forceFill($a);
        }
        public function listForDevice(string $deviceId): array { return []; }
    };
}

function makeStubIdempotency(): IdempotencyServiceInterface
{
    return new class implements IdempotencyServiceInterface {
        public function findCompletedResponse(string $idempotencyKey, string $actorId): ?array { return null; }
        public function acquireLock(string $idempotencyKey, string $actorId, string $requestHash, string $httpMethod, string $httpPath, string $correlationId): \App\Modules\Platform\Domain\Contracts\IdempotencyLock {
            return new \App\Modules\Platform\Domain\Contracts\IdempotencyLock('', $idempotencyKey, $actorId);
        }
        public function recordResponse(\App\Modules\Platform\Domain\Contracts\IdempotencyLock $lock, int $statusCode, array $responseBody): void {}
        public function recordFailure(\App\Modules\Platform\Domain\Contracts\IdempotencyLock $lock, \Throwable $e): void {}
    };
}

function makeCapturingEventPublisher(array &$captured): EventPublisherInterface
{
    return new class($captured) implements EventPublisherInterface {
        public function __construct(private array &$captured) {}
        public function publish(IntegrationEventInterface $e): void { $this->captured[] = $e; }
        public function publishAll(array $events): void {}
    };
}

function makeNullAudit(): AuditServiceInterface
{
    return new class implements AuditServiceInterface {
        public function record(AuditEventData $e): void {}
    };
}

function makeStaticConfig(array $values): ConfigurationServiceInterface
{
    return new class($values) implements ConfigurationServiceInterface {
        public function __construct(private array $v) {}
        public function get(string $key, bool $req = false): mixed { return $this->v[$key] ?? null; }
        public function getOrDefault(string $key, mixed $default): mixed { return $this->v[$key] ?? $default; }
        public function isConfigured(string $key): bool { return isset($this->v[$key]); }
        public function invalidate(string $key): void {}
    };
}

function makeNullUserRepository(): UserRepositoryInterface
{
    return new class implements UserRepositoryInterface {
        public function findByMobile(string $m): ?User { return null; }
        public function findByEmail(string $e): ?User { return null; }
        public function findById(string $i): ?User { return null; }
        public function create(array $a): User { return new User(); }
        public function updateStatus(string $id, string $status, array $extra = []): void {}
        public function incrementFailedAttempts(string $id): void {}
        public function resetFailedAttempts(string $id): void {}
        public function incrementLockoutEpisode(string $id): void {}
        public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void {}
        public function updateLastLogin(string $id): void {}
        public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void {}
    };
}

function buildHandler(
    array $repoState = [],
    int $deviceCount = 0,
    array $configValues = [],
    array &$events = [],
): RegisterExplicitDeviceHandler {
    return new RegisterExplicitDeviceHandler(
        makeStubDeviceRepo($repoState, $deviceCount),
        makeStubTrustHistoryRepo(),
        makeCapturingEventPublisher($events),
        makeNullAudit(),
        makeStaticConfig($configValues),
        makeStubIdempotency(),
        makeMetrics(),
        app(DeviceService::class),
        makeNullUserRepository(),
    );
}

function regCommand(string $userId = 'user-1', string $fingerprint = null): RegisterExplicitDeviceCommand
{
    return new RegisterExplicitDeviceCommand(
        userId:          $userId,
        deviceName:      'My Phone',
        platform:        'android',
        fingerprintHash: $fingerprint ?? str_repeat('a', 64),
        actorType:       'CUSTOMER',
        correlationId:   'corr-1',
        ipHash:          str_repeat('0', 64),
        idempotencyKey:  'idem-' . uniqid(),
    );
}

describe('RegisterExplicitDeviceHandler', function (): void {

    it('creates a new device in pending_verification status', function (): void {
        Queue::fake();

        $result = buildHandler()->handle(regCommand());

        expect($result['status'])->toBe('pending_verification');
        expect($result['device_id'])->not->toBeNull();
    });

    it('throws DeviceLimitExceededException when count meets max', function (): void {
        Queue::fake();

        expect(fn () => buildHandler(
            deviceCount:  3,
            configValues: ['device.max_devices_per_user' => '3'],
        )->handle(regCommand()))->toThrow(DeviceLimitExceededException::class);
    });

    it('returns existing device without duplicate creation (same fingerprint)', function (): void {
        Queue::fake();

        $existing = (new Device())->forceFill([
            'id'                 => 'existing-id',
            'user_id'            => 'user-1',
            'device_fingerprint' => str_repeat('a', 64),
            'status'             => 'trusted',
        ]);

        $result = buildHandler(repoState: ['existing' => $existing])->handle(regCommand());

        expect($result['device_id'])->toBe('existing-id');
        expect($result['status'])->toBe('trusted');
    });

    it('publishes DeviceRegisteredEvent for a genuinely new device', function (): void {
        Queue::fake();

        $events  = [];
        $handler = buildHandler(events: $events);
        $handler->handle(regCommand());

        expect(count($events))->toBe(1);
        expect($events[0])->toBeInstanceOf(DeviceRegisteredEvent::class);
    });

    it('does NOT publish an event when an existing device is returned', function (): void {
        Queue::fake();

        $existing = (new Device())->forceFill([
            'id'                 => 'existing-id',
            'user_id'            => 'user-1',
            'device_fingerprint' => str_repeat('a', 64),
            'status'             => 'trusted',
        ]);

        $events  = [];
        $handler = buildHandler(repoState: ['existing' => $existing], events: $events);
        $handler->handle(regCommand());

        expect(count($events))->toBe(0);
    });

})->group('identity', 'device', 'unit');
