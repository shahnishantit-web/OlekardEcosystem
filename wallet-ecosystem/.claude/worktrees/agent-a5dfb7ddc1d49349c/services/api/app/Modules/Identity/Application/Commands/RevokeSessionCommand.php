<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RevokeSessionCommand
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $adminActorId,
        public readonly string $adminActorType,
        public readonly string $correlationId,
        public readonly string $ipHash,
    ) {}
}
