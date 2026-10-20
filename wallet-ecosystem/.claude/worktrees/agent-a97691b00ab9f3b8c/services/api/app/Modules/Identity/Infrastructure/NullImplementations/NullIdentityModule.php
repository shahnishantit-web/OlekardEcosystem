<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Identity\Domain\Contracts\AuthenticatedActor;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\ServiceActor;

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
}
