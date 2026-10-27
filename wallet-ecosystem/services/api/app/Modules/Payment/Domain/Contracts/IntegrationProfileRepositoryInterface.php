<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\IntegrationProfileModel;
use Illuminate\Support\Collection;

interface IntegrationProfileRepositoryInterface
{
    public function findByIdentifier(string $identifier, string $version): ?IntegrationProfileModel;

    /** @return Collection<int, IntegrationProfileModel> */
    public function listActive(): Collection;
}
