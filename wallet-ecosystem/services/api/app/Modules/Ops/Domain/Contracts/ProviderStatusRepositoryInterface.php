<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\ProviderStatus;
use App\Modules\Ops\Infrastructure\Models\ProviderStatusModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface ProviderStatusRepositoryInterface
{
    public function upsert(string $providerType, ProviderStatus $status, int $consecutiveFailures, Carbon $lastCheckedAt): ProviderStatusModel;

    public function find(string $providerCode): ?ProviderStatusModel;

    public function findByType(string $providerType): ?ProviderStatusModel;

    /** @return Collection<int, ProviderStatusModel> */
    public function all(): Collection;
}
