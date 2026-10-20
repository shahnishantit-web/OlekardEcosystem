<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class ListDevicesCommand
{
    public function __construct(
        public string $userId,
        public int $page,
        public int $perPage,
        public ?string $status,
        public ?string $platform,
    ) {}
}
