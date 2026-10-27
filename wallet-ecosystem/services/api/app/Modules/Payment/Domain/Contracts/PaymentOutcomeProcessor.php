<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

interface PaymentOutcomeProcessor
{
    /** Verified, server-bound outcome only; implementation owns atomic financial semantics. */
    public function handleOutcome(array $request): void;
}
