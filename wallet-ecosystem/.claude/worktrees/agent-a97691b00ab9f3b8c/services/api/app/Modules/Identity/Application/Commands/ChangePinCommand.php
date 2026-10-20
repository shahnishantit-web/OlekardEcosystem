<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class ChangePinCommand
{
    public function __construct(
        public string $userId,
        public string $currentPin,
        public string $newPin,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
