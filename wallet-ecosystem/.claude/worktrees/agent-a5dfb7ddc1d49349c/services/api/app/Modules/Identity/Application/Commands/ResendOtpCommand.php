<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class ResendOtpCommand
{
    public function __construct(
        public string $challengeId,
        public string $correlationId,
        public string $idempotencyKey,
        public string $ipHash,
    ) {}
}
