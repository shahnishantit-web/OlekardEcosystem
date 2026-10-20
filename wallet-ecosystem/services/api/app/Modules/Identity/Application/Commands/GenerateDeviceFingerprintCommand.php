<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class GenerateDeviceFingerprintCommand
{
    public function __construct(
        public string $userId,
        public string $platform,
        public array $attributes,
        public string $actorType,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
