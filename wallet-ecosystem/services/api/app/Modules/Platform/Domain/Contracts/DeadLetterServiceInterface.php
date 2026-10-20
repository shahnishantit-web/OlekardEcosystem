<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface DeadLetterServiceInterface
{
    public function capture(
        string $operationType,
        string $sourceId,
        array $payload,
        string $failureReason,
        int $retryCount,
        array $retryHistory,
        string $correlationId,
        ?Model $sourceModel = null,
    ): DeadLetterItemModel;

    public function findById(string $id): ?DeadLetterItemModel;

    public function list(array $filters, int $perPage): LengthAwarePaginator;

    public function replay(
        string $id,
        string $actorId,
        ?string $note,
        string $correlationId,
    ): DeadLetterItemModel;

    public function dismiss(
        string $id,
        string $actorId,
        string $reason,
        string $correlationId,
    ): DeadLetterItemModel;

    public function stats(): array;
}
