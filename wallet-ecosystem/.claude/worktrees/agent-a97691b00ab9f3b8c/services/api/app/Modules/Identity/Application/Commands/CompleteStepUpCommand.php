<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class CompleteStepUpCommand
{
    public function __construct(
        public string $stepUpToken,
        public string $method,
        public string $credential,
        public string $userId,
        public string $sessionId,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
