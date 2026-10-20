<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RegisterExplicitDeviceCommand
{
    public function __construct(
        public string $userId,
        public string $deviceName,
        public string $platform,
        public string $fingerprintHash,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
        public string $idempotencyKey,
    ) {}
}
