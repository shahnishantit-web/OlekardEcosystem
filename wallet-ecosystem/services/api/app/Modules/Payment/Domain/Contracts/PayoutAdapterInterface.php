<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

/**
 * @todo AD-14 — Payout contract ownership must be resolved before implementing
 *              initiatePayout() and queryPayoutStatus() method signatures.
 *              Options: (A) extend PaymentAdapterInterface, (B) standalone interface here,
 *              (C) new App\Modules\Payout module.
 */
interface PayoutAdapterInterface
{
    // STUB — BLOCKED AD-14: no method signatures until architectural decision resolved
}
