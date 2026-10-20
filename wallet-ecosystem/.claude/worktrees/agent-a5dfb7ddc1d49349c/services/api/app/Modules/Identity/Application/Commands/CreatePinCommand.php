<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class CreatePinCommand
{
    public function __construct(
        public string  $userId,
        public string  $pin,
        public string  $correlationId,
        public string  $ipHash,
    ) {}
}
