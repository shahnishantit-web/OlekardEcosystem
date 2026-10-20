<?php

declare(strict_types=1);

use App\Modules\Identity\Application\IdentityModule;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

function makeIdentityModule(UserRepositoryInterface $users): IdentityModule
{
    $jwks = new class implements JwksProviderContract {
        public function getSigningKey(): \App\Modules\Identity\Domain\Contracts\JwkSigningKey
        {
            throw new \BadMethodCallException('Not needed for contact tests.');
        }
        public function getVerificationKeys(): array { return []; }
        public function getCurrentKid(): string { return ''; }
    };

    $sessions = new class implements SessionRepositoryInterface {
        public function create(array $attributes): \App\Modules\Identity\Infrastructure\Models\Session { throw new \BadMethodCallException(); }
        public function findById(string $id): ?\App\Modules\Identity\Infrastructure\Models\Session { return null; }
        public function findByRefreshHash(string $hash): ?\App\Modules\Identity\Infrastructure\Models\Session { return null; }
        public function findByFamilyId(string $familyId): array { return []; }
        public function updateStatus(string $id, string $status): void {}
        public function revokeAllForUser(string $userId): void {}
        public function revokeFamily(string $familyId): void {}
        public function updateLastActivity(string $id): void {}
        public function listActiveForUser(string $userId): array { return []; }
        public function listActiveForDevice(string $deviceId): array { return []; }
        public function revokeAllForDevice(string $deviceId): int { return 0; }
    };

    $configStub = new class implements ConfigurationServiceInterface {
        public function get(string $key, bool $requireValue = false): mixed { return null; }
        public function getOrDefault(string $key, mixed $default): mixed { return $default; }
        public function isConfigured(string $key): bool { return false; }
        public function invalidate(string $key): void {}
    };

    $config = new ConfigurationSecurityService($configStub);

    return new IdentityModule($jwks, $sessions, $config, $users);
}

function makeUserStub(string $id, ?string $email, ?string $mobile, string $actorType = 'CUSTOMER'): User
{
    $user = new User();
    $user->forceFill([
        'id'            => $id,
        'email'         => $email,
        'mobile_number' => $mobile,
        'actor_type'    => $actorType,
        'status'        => 'active',
    ]);
    return $user;
}

describe('IdentityModule — contact info', function (): void {

    it('returns UserContactInfo DTO when user exists', function (): void {
        $userId = 'user-uuid-001';
        $stub   = makeUserStub($userId, 'alice@example.com', '+26771234567');

        $repo = new class($userId, $stub) implements UserRepositoryInterface {
            public function __construct(private string $expectedId, private User $user) {}
            public function findById(string $id): ?User { return $id === $this->expectedId ? $this->user : null; }
            public function findByMobile(string $n): ?User { return null; }
            public function findByEmail(string $e): ?User { return null; }
            public function create(array $a): User { throw new \BadMethodCallException(); }
            public function updateStatus(string $id, string $status, array $extra = []): void {}
            public function incrementFailedAttempts(string $id): void {}
            public function resetFailedAttempts(string $id): void {}
            public function incrementLockoutEpisode(string $id): void {}
            public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void {}
            public function updateLastLogin(string $id): void {}
            public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void {}
        };

        $module = makeIdentityModule($repo);
        $info   = $module->getUserContactInfo($userId);

        expect($info)->toBeInstanceOf(UserContactInfo::class);
        expect($info->userId)->toBe($userId);
        expect($info->email)->toBe('alice@example.com');
        expect($info->mobileNumber)->toBe('+26771234567');
        expect($info->actorType)->toBe('CUSTOMER');
    })->group('identity', 'module', 'contact');

    it('returns null when user does not exist', function (): void {
        $repo = new class implements UserRepositoryInterface {
            public function findById(string $id): ?User { return null; }
            public function findByMobile(string $n): ?User { return null; }
            public function findByEmail(string $e): ?User { return null; }
            public function create(array $a): User { throw new \BadMethodCallException(); }
            public function updateStatus(string $id, string $status, array $extra = []): void {}
            public function incrementFailedAttempts(string $id): void {}
            public function resetFailedAttempts(string $id): void {}
            public function incrementLockoutEpisode(string $id): void {}
            public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void {}
            public function updateLastLogin(string $id): void {}
            public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void {}
        };

        $module = makeIdentityModule($repo);

        expect($module->getUserContactInfo('nonexistent-id'))->toBeNull();
    })->group('identity', 'module', 'contact');

    it('delegates updateUserContact to the repository with correct arguments', function (): void {
        $called = [];

        $repo = new class($called) implements UserRepositoryInterface {
            public function __construct(private array &$called) {}
            public function findById(string $id): ?User { return null; }
            public function findByMobile(string $n): ?User { return null; }
            public function findByEmail(string $e): ?User { return null; }
            public function create(array $a): User { throw new \BadMethodCallException(); }
            public function updateStatus(string $id, string $status, array $extra = []): void {}
            public function incrementFailedAttempts(string $id): void {}
            public function resetFailedAttempts(string $id): void {}
            public function incrementLockoutEpisode(string $id): void {}
            public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void {}
            public function updateLastLogin(string $id): void {}
            public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void
            {
                $this->called[] = compact('userId', 'email', 'mobile');
            }
        };

        $module = makeIdentityModule($repo);
        $module->updateUserContact('user-001', 'new@example.com', null);

        expect($called)->toHaveCount(1);
        expect($called[0]['userId'])->toBe('user-001');
        expect($called[0]['email'])->toBe('new@example.com');
        expect($called[0]['mobile'])->toBeNull();
    })->group('identity', 'module', 'contact');

    it('propagates IdentityContactUpdateException from the repository', function (): void {
        $repo = new class implements UserRepositoryInterface {
            public function findById(string $id): ?User { return null; }
            public function findByMobile(string $n): ?User { return null; }
            public function findByEmail(string $e): ?User { return null; }
            public function create(array $a): User { throw new \BadMethodCallException(); }
            public function updateStatus(string $id, string $status, array $extra = []): void {}
            public function incrementFailedAttempts(string $id): void {}
            public function resetFailedAttempts(string $id): void {}
            public function incrementLockoutEpisode(string $id): void {}
            public function updateLockedAt(string $id, \DateTimeInterface $lockedAt): void {}
            public function updateLastLogin(string $id): void {}
            public function updateContactInfo(string $userId, ?string $email, ?string $mobile): void
            {
                throw new IdentityContactUpdateException('duplicate email');
            }
        };

        $module = makeIdentityModule($repo);

        expect(fn () => $module->updateUserContact('user-001', 'dup@example.com', null))
            ->toThrow(IdentityContactUpdateException::class, 'duplicate email');
    })->group('identity', 'module', 'contact');

})->group('identity', 'module', 'contact');
