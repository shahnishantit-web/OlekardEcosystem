<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\IntegrationProfileRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\IntegrationProfileModel;
use Illuminate\Support\Collection;

final class EloquentIntegrationProfileRepository implements IntegrationProfileRepositoryInterface
{
    public function findByIdentifier(string $identifier, string $version): ?IntegrationProfileModel
    {
        return IntegrationProfileModel::query()
            ->where('profile_identifier', $identifier)
            ->where('version', $version)
            ->first();
    }

    /** @return Collection<int, IntegrationProfileModel> */
    public function listActive(): Collection
    {
        return IntegrationProfileModel::query()
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();
    }
}
