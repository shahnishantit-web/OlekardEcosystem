<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RefreshTokenCommand
{
    public function __construct(
        public string $rawRefreshToken,
        public string $correlationId,
        public string $idempotencyKey,
        public string $ipHash,
    ) {}
}
