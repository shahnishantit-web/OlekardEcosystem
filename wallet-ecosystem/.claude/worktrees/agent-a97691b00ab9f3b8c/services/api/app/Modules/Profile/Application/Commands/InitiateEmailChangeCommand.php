<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

final readonly class InitiateEmailChangeCommand
{
    public function __construct(
        public string $userId,
        public string $actorId,
        public string $actorType,
        public string $newEmail,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
