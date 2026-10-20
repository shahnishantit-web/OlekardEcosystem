<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Repositories;

use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Support\Collection;

interface RoleRepositoryInterface
{
    public function findAll(): Collection;

    public function findById(string $id): ?Role;

    public function findBySlug(string $slug): ?Role;

    public function create(array $data): Role;

    public function softDelete(string $id): void;

    /** Returns all role slugs for the actor: default_role + any additional user_roles. */
    public function getActorRoleSlugs(string $actorId): array;
}
