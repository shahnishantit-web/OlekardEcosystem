<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\AuthenticatedActor;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Profile\Infrastructure\Listeners\CreateProfileOnUserRegisteredListener;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Ramsey\Uuid\Uuid;

/**
 * T038 — Integration test: when getUserContactInfo() throws, the event is NOT acknowledged
 * and no partial profile record is persisted. Horizon will retry the dispatch.
 *
 * Note: T169 gate — until E01 T239–T244 are complete, getUserContactInfo() throws
 * BadMethodCallException at runtime. These tests verify the rollback behavior.
 */
describe('CreateProfileOnUserRegisteredListener — error rollback', function (): void {

    it('does not persist a partial profile record when getUserContactInfo throws', function (): void {
        $userId = (string) Uuid::uuid7();

        // Bind a stub that always throws
        app()->bind(IdentityModuleContract::class, fn () => new class implements IdentityModuleContract {
            public function resolveActorFromToken(string $bearerToken): ?AuthenticatedActor { return null; }
            public function resolveServiceActor(string $bearerToken): ?ServiceActor { return null; }
            public function verifyStepUpScope(string $stepUpToken, string $requiredScope): bool { return false; }
            public function revokeSession(string $sessionId, string $revokedByActorId): void {}
            public function getUserContactInfo(string $userId): ?UserContactInfo
            {
                throw new \BadMethodCallException('E01 T239-T244 not complete');
            }
            public function updateUserContact(string $userId, ?string $email, ?string $mobile): void
            {
                throw new \BadMethodCallException('E01 T239-T244 not complete');
            }
        });

        $listener = app(CreateProfileOnUserRegisteredListener::class);
        $profiles = app(EloquentUserProfileRepository::class);

        expect(fn () => $listener->handle('Identity.User.Registered', [
            'user_id'        => $userId,
            'correlation_id' => (string) Uuid::uuid7(),
            'actor_type'     => 'CUSTOMER',
        ]))->toThrow(\BadMethodCallException::class);

        // No partial profile record persisted
        expect($profiles->exists($userId))->toBeFalse();
    });
});
