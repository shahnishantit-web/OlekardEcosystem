<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Identity\Domain\Contracts\AuthenticatedActor;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;

final class NullIdentityModule implements IdentityModuleContract
{
    public function resolveActorFromToken(string $bearerToken): ?AuthenticatedActor
    {
        throw new ModuleNotImplementedException('Identity', 'resolveActorFromToken');
    }

    public function resolveServiceActor(string $bearerToken): ?ServiceActor
    {
        throw new ModuleNotImplementedException('Identity', 'resolveServiceActor');
    }

    public function verifyStepUpScope(string $stepUpToken, string $requiredScope): bool
    {
        throw new ModuleNotImplementedException('Identity', 'verifyStepUpScope');
    }

    public function revokeSession(string $sessionId, string $revokedByActorId): void
    {
        throw new ModuleNotImplementedException('Identity', 'revokeSession');
    }

    public function getUserContactInfo(string $userId): ?UserContactInfo
    {
        throw new ModuleNotImplementedException('Identity', 'getUserContactInfo');
    }

    public function updateUserContact(string $userId, ?string $email, ?string $mobile): void
    {
        throw new ModuleNotImplementedException('Identity', 'updateUserContact');
    }

    public function hasPermission(string $actorId, string $permission): bool
    {
        throw new ModuleNotImplementedException('Identity', 'hasPermission');
    }

    public function findCustomerIdByMobile(string $mobileNumber): ?string
    {
        throw new ModuleNotImplementedException('Identity', 'findCustomerIdByMobile');
    }

    public function verifyTransactionPin(string $actorId, string $pin, bool $requireStepUp = false): bool
    {
        throw new ModuleNotImplementedException('Identity', 'verifyTransactionPin');
    }

    public function getIdentitySnapshot(string $userId): ?array
    {
        throw new ModuleNotImplementedException('Identity', 'getIdentitySnapshot');
    }
}
