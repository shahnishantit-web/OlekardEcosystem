<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class UnblockDeviceCommand
{
    public function __construct(
        public string $adminActorId,
        public string $deviceId,
        public string $reason,
        public string $correlationId,
        public string $ipHash,
        public string $idempotencyKey,
    ) {}
}
