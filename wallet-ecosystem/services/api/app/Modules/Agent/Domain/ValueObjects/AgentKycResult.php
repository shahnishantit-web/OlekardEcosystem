<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\ValueObjects;

final readonly class AgentKycResult
{
    public function __construct(
        public bool    $passed,
        public string  $reference,
        public ?string $failureReason = null,
    ) {}
}
