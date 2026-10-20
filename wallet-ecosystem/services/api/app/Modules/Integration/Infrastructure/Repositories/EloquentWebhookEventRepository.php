<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Repositories;

use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentWebhookEventRepository implements WebhookEventRepositoryInterface
{
    public function findById(string $id): ?WebhookEventModel
    {
        return WebhookEventModel::find($id);
    }

    public function findByIdempotencyKey(string $providerSlug, string $idempotencyKey): ?WebhookEventModel
    {
        return WebhookEventModel::where('provider_slug', $providerSlug)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = WebhookEventModel::query()->orderBy('received_at', 'desc');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['provider_slug'])) {
            $query->where('provider_slug', $filters['provider_slug']);
        }

        if (!empty($filters['provider_type'])) {
            $query->where('provider_type', $filters['provider_type']);
        }

        return $query->paginate($perPage);
    }

    public function save(WebhookEventModel $model): void
    {
        $model->save();
    }
}
