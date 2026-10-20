<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Repositories;

use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Support\Collection;

interface PermissionRepositoryInterface
{
    public function findAll(): Collection;

    public function findById(string $id): ?Permission;

    public function findBySlug(string $slug): ?Permission;
}
