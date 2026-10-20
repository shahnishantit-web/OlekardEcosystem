<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Contracts\ConfigurationVersionRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentConfigurationVersionRepository implements ConfigurationVersionRepositoryInterface
{
    public function insert(array $attributes): void
    {
        // Direct DB insert bypasses the immutable model guard (FR-016)
        DB::connection('platform')->table('platform.configuration_versions')->insert(
            array_merge(['created_at' => now()], $attributes)
        );
    }

    public function paginateByKey(
        string  $configKey,
        int     $perPage,
        ?string $from      = null,
        ?string $to        = null,
        ?string $makerId   = null,
        ?string $checkerId = null,
        ?string $domain    = null,
    ): LengthAwarePaginator {
        $query = DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('config_key', $configKey);

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($makerId !== null) {
            $query->where('maker_id', $makerId);
        }

        if ($checkerId !== null) {
            $query->where('checker_id', $checkerId);
        }

        if ($domain !== null) {
            $query->where('domain', $domain);
        }

        return $query->orderByDesc('occurred_at')->paginate($perPage);
    }

    public function findByChangeRequest(string $changeRequestId): array
    {
        return DB::connection('platform')
            ->table('platform.configuration_versions')
            ->where('change_request_id', $changeRequestId)
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
