<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class LogoutCommand
{
    public function __construct(
        public string $sessionId,
        public string $userId,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
