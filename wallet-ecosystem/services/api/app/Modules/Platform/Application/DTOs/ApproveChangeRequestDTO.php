<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\DTOs;

final readonly class ApproveChangeRequestDTO
{
    public function __construct(
        public string $changeRequestId,
        public string $checkerId,
        public string $idempotencyKey,
        public string $correlationId,
        public ?string $comments = null,
    ) {}
}
