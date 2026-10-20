<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class BlockDeviceCommand
{
    public function __construct(
        public string $userId,
        public string $deviceId,
        public ?string $reason,
        public bool $isAdminAction,
        public ?string $adminActorId,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
        public string $idempotencyKey,
    ) {}
}
