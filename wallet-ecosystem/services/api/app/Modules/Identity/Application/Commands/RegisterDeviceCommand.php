<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RegisterDeviceCommand
{
    public function __construct(
        public string $userId,
        public string $rawFingerprint,
        public string $deviceName,
        public string $platform,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
