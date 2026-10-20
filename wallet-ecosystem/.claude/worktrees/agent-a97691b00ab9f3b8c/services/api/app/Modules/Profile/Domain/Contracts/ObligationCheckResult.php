<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Contracts;

readonly class ObligationCheckResult
{
    public function __construct(
        public bool  $hasObligations,
        public array $blockedBy,
    ) {}
}
