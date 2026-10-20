<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

final class IdempotencyLock
{
    public function __construct(
        public readonly string $recordId,
        public readonly string $idempotencyKey,
        public readonly string $actorId,
    ) {}
}
