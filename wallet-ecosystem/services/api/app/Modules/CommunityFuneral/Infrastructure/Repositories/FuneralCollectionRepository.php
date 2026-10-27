<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Infrastructure\Repositories;

use App\Modules\CommunityFuneral\Domain\Contracts\FuneralCollectionRepositoryInterface;
use App\Modules\CommunityFuneral\Domain\Models\FuneralCollection;

final class FuneralCollectionRepository implements FuneralCollectionRepositoryInterface
{
    public function findById(string $id): ?FuneralCollection
    {
        return FuneralCollection::query()->where('id', $id)->first();
    }

    public function findByIdWithLock(string $id): ?FuneralCollection
    {
        return FuneralCollection::query()->where('id', $id)->lockForUpdate()->first();
    }

    public function save(FuneralCollection $collection): bool
    {
        return $collection->save();
    }
}
