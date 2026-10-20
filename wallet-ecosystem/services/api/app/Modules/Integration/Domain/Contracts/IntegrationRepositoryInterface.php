<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Contracts;

use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use Illuminate\Support\Collection;

interface IntegrationRepositoryInterface
{
    public function findById(string $id): ?IntegrationModel;

    public function findDefault(IntegrationProviderType $type): ?IntegrationModel;

    public function all(array $filters = []): Collection;

    public function save(IntegrationModel $model): void;

    public function updateStatus(string $id, IntegrationStatus $status): IntegrationModel;

    public function demoteDefaultsForType(IntegrationProviderType $type, string $exceptId): void;
}
