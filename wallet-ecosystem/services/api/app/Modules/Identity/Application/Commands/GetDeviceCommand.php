<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class GetDeviceCommand
{
    public function __construct(
        public string $userId,
        public string $deviceId,
    ) {}
}
