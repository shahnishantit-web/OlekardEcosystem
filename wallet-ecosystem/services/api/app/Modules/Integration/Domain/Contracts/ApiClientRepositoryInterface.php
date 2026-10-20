<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Contracts;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ApiClientRepositoryInterface
{
    public function findById(string $id): ?ApiClientModel;

    public function findByApiKey(string $apiKey): ?ApiClientModel;

    public function paginate(int $perPage = 20): LengthAwarePaginator;

    public function save(ApiClientModel $model): void;

    public function updateStatus(string $id, ApiClientStatus $status): void;
}
