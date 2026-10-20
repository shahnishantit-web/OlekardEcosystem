<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Contracts;

use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WebhookEventRepositoryInterface
{
    public function findById(string $id): ?WebhookEventModel;

    public function findByIdempotencyKey(string $providerSlug, string $idempotencyKey): ?WebhookEventModel;

    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    public function save(WebhookEventModel $model): void;
}
