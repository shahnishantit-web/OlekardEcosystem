<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\DTOs;

final readonly class WithdrawChangeRequestDTO
{
    public function __construct(
        public string $changeRequestId,
        public string $makerId,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
