<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Repositories;

use App\Modules\Integration\Domain\Contracts\ApiClientRepositoryInterface;
use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentApiClientRepository implements ApiClientRepositoryInterface
{
    public function findById(string $id): ?ApiClientModel
    {
        return ApiClientModel::find($id);
    }

    public function findByApiKey(string $apiKey): ?ApiClientModel
    {
        return ApiClientModel::where('api_key', $apiKey)->first();
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ApiClientModel::orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function save(ApiClientModel $client): void
    {
        $client->save();
    }

    public function updateStatus(string $id, ApiClientStatus $status): void
    {
        ApiClientModel::where('id', $id)->update(['status' => $status->value]);
    }
}
