<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class DisableMfaCommand
{
    public function __construct(
        public string $userId,
        public string $method,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
