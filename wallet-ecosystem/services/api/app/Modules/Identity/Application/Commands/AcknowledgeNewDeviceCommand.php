<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class AcknowledgeNewDeviceCommand
{
    public function __construct(
        public string $userId,
        public string $deviceId,
        public bool $acknowledged,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
        public string $idempotencyKey,
    ) {}
}
