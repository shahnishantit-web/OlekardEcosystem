<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class DetectDeviceChangeCommand
{
    public function __construct(
        public string $deviceId,
        public string $userId,
        public string $submittedFingerprintHash,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
        public string $previousFingerprintHash = '',
    ) {}
}
