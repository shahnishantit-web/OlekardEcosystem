<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class VerifyMfaCommand
{
    public function __construct(
        public string $mfaToken,
        public string $method,
        public string $code,
        public string $correlationId,
        public string $ipHash,
    ) {}
}
