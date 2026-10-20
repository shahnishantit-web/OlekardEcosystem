<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Repositories\PermissionRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Support\Collection;

final class EloquentPermissionRepository implements PermissionRepositoryInterface
{
    public function findAll(): Collection
    {
        return Permission::whereNull('deleted_at')->get();
    }

    public function findById(string $id): ?Permission
    {
        return Permission::find($id);
    }

    public function findBySlug(string $slug): ?Permission
    {
        return Permission::where('slug', $slug)->whereNull('deleted_at')->first();
    }
}
