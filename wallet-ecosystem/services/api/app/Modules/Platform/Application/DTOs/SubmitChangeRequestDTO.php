<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\DTOs;

final readonly class SubmitChangeRequestDTO
{
    public function __construct(
        public string $configKey,
        public mixed $proposedValue,
        public string $makerId,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
