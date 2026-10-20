<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\ProviderStatusRepositoryInterface;
use App\Modules\Ops\Domain\Enums\ProviderStatus;
use App\Modules\Ops\Infrastructure\Models\ProviderStatusModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentProviderStatusRepository implements ProviderStatusRepositoryInterface
{
    public function upsert(string $providerType, ProviderStatus $status, int $consecutiveFailures, Carbon $lastCheckedAt): ProviderStatusModel
    {
        $existing = ProviderStatusModel::where('provider_type', $providerType)->first();

        if ($existing !== null) {
            $updates = [
                'status'               => $status->value,
                'consecutive_failures' => $consecutiveFailures,
                'last_checked_at'      => $lastCheckedAt,
            ];

            if ($status === ProviderStatus::Degraded && $existing->degraded_since === null) {
                $updates['degraded_since'] = Carbon::now();
            }
            if ($status === ProviderStatus::Down && $existing->down_since === null) {
                $updates['down_since'] = Carbon::now();
            }
            if ($status === ProviderStatus::Up) {
                $updates['degraded_since'] = null;
                $updates['down_since']     = null;
            }

            $existing->update($updates);
            return $existing->refresh();
        }

        return ProviderStatusModel::create([
            'id'                   => Str::orderedUuid()->toString(),
            'provider_type'        => $providerType,
            'status'               => $status->value,
            'consecutive_failures' => $consecutiveFailures,
            'last_checked_at'      => $lastCheckedAt,
        ]);
    }

    public function find(string $providerCode): ?ProviderStatusModel
    {
        return ProviderStatusModel::where('provider_type', $providerCode)->first();
    }

    public function findByType(string $providerType): ?ProviderStatusModel
    {
        return ProviderStatusModel::where('provider_type', $providerType)->first();
    }

    public function all(): Collection
    {
        return ProviderStatusModel::orderBy('provider_type')->get();
    }
}
