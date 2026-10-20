<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\ActorContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Auth\Access\AuthorizationException;

final class AuthorizationGuard
{
    public function __construct(
        private readonly IdentityModuleContract $identity,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Require that the actor has the given permission.
     * Permission naming convention: {module}.{resource}.{action} (e.g., configuration.policy.update)
     */
    public function require(ActorContract $actor, string $permission, string $correlationId): void
    {
        $granted = $this->identity->hasPermission($actor->id(), $permission);

        if (! $granted) {
            $this->audit->record(new AuditEventData(
                action: 'platform.permission.denied',
                actorType: $actor->type(),
                resourceType: 'Permission',
                correlationId: $correlationId,
                outcome: 'failure',
                actorId: $actor->id(),
                metadata: ['permission' => $permission],
            ));

            throw new AuthorizationException("Permission denied: {$permission}");
        }
    }
}
