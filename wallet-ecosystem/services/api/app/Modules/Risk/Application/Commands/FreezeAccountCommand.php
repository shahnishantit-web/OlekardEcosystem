<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

final class FreezeAccountCommand
{
    public function __construct(
        public readonly string  $subjectType,
        public readonly string  $subjectId,
        public readonly string  $reason,
        public readonly string  $placedBy,
        public readonly ?string $checkerId,
        public readonly ?string $walletId   = null,
        public readonly ?string $alertId    = null,
        public readonly ?string $decisionId = null,
        public readonly ?string $expiresAt  = null,
    ) {}
}
