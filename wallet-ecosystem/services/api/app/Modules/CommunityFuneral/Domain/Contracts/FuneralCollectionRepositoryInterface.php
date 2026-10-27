<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Contracts;

use App\Modules\CommunityFuneral\Domain\Models\FuneralCollection;

interface FuneralCollectionRepositoryInterface
{
    public function findById(string $id): ?FuneralCollection;

    public function findByIdWithLock(string $id): ?FuneralCollection;

    public function save(FuneralCollection $collection): bool;
}
