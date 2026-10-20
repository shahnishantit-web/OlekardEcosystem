<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Infrastructure\Models\DeploymentVersionModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface DeploymentVersionRepositoryInterface
{
    public function create(string $version, ?string $gitSha, ?string $releaseNotes, ?string $deployedBy): DeploymentVersionModel;

    public function findByVersion(string $version): ?DeploymentVersionModel;

    public function findOrFail(string $id): DeploymentVersionModel;

    public function current(): ?DeploymentVersionModel;

    /** @return LengthAwarePaginator<DeploymentVersionModel> */
    public function paginate(int $perPage): LengthAwarePaginator;
}
