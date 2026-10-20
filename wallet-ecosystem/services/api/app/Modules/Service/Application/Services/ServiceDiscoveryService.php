<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ServiceDiscoveryService
{
    public function list(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = ServiceModel::where('status', ServiceStatus::Active->value);

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('description', 'ilike', $search);
            });
        }

        return $query->orderBy('name')->paginate(perPage: $perPage, page: $page);
    }

    public function show(string $id): ServiceModel
    {
        $service = ServiceModel::where('id', $id)
            ->where('status', ServiceStatus::Active->value)
            ->first();

        if ($service === null) {
            throw new ServiceNotFoundException($id);
        }

        return $service;
    }
}
