<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\DTOs;

final readonly class RejectChangeRequestDTO
{
    public function __construct(
        public string $changeRequestId,
        public string $checkerId,
        public string $reason,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
