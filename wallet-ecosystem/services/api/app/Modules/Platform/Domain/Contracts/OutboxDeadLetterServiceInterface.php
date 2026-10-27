<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface OutboxDeadLetterServiceInterface
{
    /**
     * @throws ModelNotFoundException
     * @throws \App\Modules\Platform\Domain\Exceptions\OutboxConflictException
     */
    public function replay(string $id, string $actorId): OutboxEventModel;

    /**
     * @param array{event_name?: string, correlation_id?: string} $filters
     */
    public function listDeadLetters(array $filters, int $perPage): array;
}
