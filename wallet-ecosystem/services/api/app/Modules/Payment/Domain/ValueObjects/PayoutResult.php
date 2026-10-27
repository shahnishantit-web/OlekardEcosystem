<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\PayoutOutcome;

final readonly class PayoutResult
{
    public function __construct(public PayoutOutcome $outcome, public ?string $providerReference) {}
}
