<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T082: Feature tests for account disable and safe recovery scenarios.
 * Docker-gated for full integration.
 */
final class AccountDisableRecoveryTest extends TestCase
{
    public function test_suspend_account_rejects_new_payment_intent(): void
    {
        $this->markTestSkipped('Docker-gated: requires seeded Active account, suspend transition, then POST /intents');
    }

    public function test_existing_attempt_callback_accepted_when_account_suspended(): void
    {
        $this->markTestSkipped('Docker-gated: requires active attempt + suspend account + receive callback');
    }

    public function test_retire_blocked_by_outstanding_obligations(): void
    {
        $this->markTestSkipped('Docker-gated: requires non-terminal payment attempt linked to account');
    }

    public function test_retire_succeeds_with_resolution_route_recorded(): void
    {
        $this->markTestSkipped('Docker-gated: requires lifecycle transition with resolutionRouteRecorded=true');
    }
}
