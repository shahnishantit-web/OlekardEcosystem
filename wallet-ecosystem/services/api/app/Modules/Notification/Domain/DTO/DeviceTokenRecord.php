<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\DTO;

final readonly class DeviceTokenRecord
{
    public function __construct(
        public string $id,
        public string $tokenValue,
        public string $platform,
    ) {}
}
