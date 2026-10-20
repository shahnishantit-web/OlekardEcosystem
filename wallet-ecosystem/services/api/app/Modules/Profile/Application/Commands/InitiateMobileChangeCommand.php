<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

final readonly class InitiateMobileChangeCommand
{
    public function __construct(
        public string $userId,
        public string $actorId,
        public string $actorType,
        public string $newMobile,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
