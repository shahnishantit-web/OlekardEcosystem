<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Repositories;

interface UserRoleRepositoryInterface
{
    /** Returns all role UUIDs assigned to the actor via identity.user_roles. */
    public function getActorRoleIds(string $actorId): array;

    public function assignRole(string $userId, string $roleId, string $assignedByActorId): void;

    public function removeRole(string $userId, string $roleId): void;
}
