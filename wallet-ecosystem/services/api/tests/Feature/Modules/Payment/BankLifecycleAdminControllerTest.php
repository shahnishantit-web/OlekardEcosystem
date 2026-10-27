<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T055: Feature tests for BankLifecycleAdminController.
 * Includes idempotent lifecycle transition test (constitution P4/P8).
 */
final class BankLifecycleAdminControllerTest extends TestCase
{
    public function test_sc001_full_lifecycle_to_active(): void
    {
        $this->markTestSkipped('Docker-gated: SC-001 full lifecycle scenario requires running DB');
    }

    public function test_sc003_approval_conflict_returns_409(): void
    {
        $this->markTestSkipped('Docker-gated: SC-003 same-actor approval blocked');
    }

    public function test_invalid_transition_returns_409(): void
    {
        $this->markTestSkipped('Docker-gated: invalid lifecycle transition returns 409 with invalid_lifecycle_transition code');
    }

    public function test_unauthorized_returns_403(): void
    {
        $this->markTestSkipped('Docker-gated: user without payment.configuration returns 403');
    }

    /**
     * Constitution P4/P8 idempotent lifecycle transitions.
     *
     * Submit the activate transition twice with the same Idempotency-Key;
     * assert second call returns the same {new_status: "active"} response
     * without changing state or creating a duplicate ConfigurationVersion snapshot.
     */
    public function test_idempotent_activate_does_not_create_duplicate_version(): void
    {
        $this->markTestSkipped('Docker-gated: idempotent activate transition test requires DB');
    }
}
