<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\DeploymentVersionRepositoryInterface;
use App\Modules\Ops\Domain\Exceptions\DeploymentVersionNotFoundException;
use App\Modules\Ops\Infrastructure\Models\DeploymentVersionModel;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentDeploymentVersionRepository implements DeploymentVersionRepositoryInterface
{
    public function create(string $version, ?string $gitSha, ?string $releaseNotes, ?string $deployedBy): DeploymentVersionModel
    {
        DeploymentVersionModel::where('is_current', true)->update(['is_current' => false]);

        return DeploymentVersionModel::create([
            'id'            => Str::orderedUuid()->toString(),
            'version'       => $version,
            'git_sha'       => $gitSha,
            'release_notes' => $releaseNotes,
            'deployed_by'   => $deployedBy,
            'deployed_at'   => Carbon::now(),
            'is_current'    => true,
        ]);
    }

    public function findByVersion(string $version): ?DeploymentVersionModel
    {
        return DeploymentVersionModel::where('version', $version)->first();
    }

    public function findOrFail(string $id): DeploymentVersionModel
    {
        return DeploymentVersionModel::find($id) ?? throw new DeploymentVersionNotFoundException($id);
    }

    public function current(): ?DeploymentVersionModel
    {
        return DeploymentVersionModel::where('is_current', true)->first();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return DeploymentVersionModel::orderByDesc('deployed_at')->paginate($perPage);
    }
}
