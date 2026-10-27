<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T099: Unknown provider status must never default to payment success — SC-002 variant.
 * Docker-gated for full integration.
 */
final class UnknownProviderStatusTest extends TestCase
{
    public function test_unknown_status_leaves_attempt_unresolved(): void
    {
        $this->markTestSkipped('Docker-gated: requires injecting unknown_status into mock adapter response and asserting attempt.status != success');
    }

    public function test_unknown_status_does_not_advance_accounting_status(): void
    {
        $this->markTestSkipped('Docker-gated: requires checking payment_intents.accounting_status after unknown-status callback');
    }
}
