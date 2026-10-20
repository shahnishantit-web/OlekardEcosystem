<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface DeadLetterRepositoryInterface
{
    public function save(DeadLetterItemModel $item): void;

    public function findById(string $id): ?DeadLetterItemModel;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function countByStatus(): array;
}
