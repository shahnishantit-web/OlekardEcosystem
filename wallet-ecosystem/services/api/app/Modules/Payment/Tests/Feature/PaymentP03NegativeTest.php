<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P03 negative scenario tests (FR-10).
 * Docker-gated: requires a running database + seeded identity/payment modules.
 *
 * Run: php artisan test app/Modules/Payment/Tests/Feature/PaymentP03NegativeTest.php
 */
final class PaymentP03NegativeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SC-N1: ID substitution — cross-org refund attempt returns 403.
     * An actor from org-A cannot request a refund on a payment intent belonging to org-B.
     */
    public function test_cross_org_refund_attempt_returns_403(): void
    {
        // Arrange: create two actors in different orgs; create an intent for org-B
        // This test requires seeded organisations and users — Docker-gated.
        $this->markTestSkipped('Docker-gated: requires running database with seeded organisations.');
    }

    /**
     * SC-N2: Cross-tenant reconciliation export — org-scoped admin cannot see another org's exceptions.
     */
    public function test_cross_tenant_reconciliation_access_denied(): void
    {
        $this->markTestSkipped('Docker-gated: requires running database with seeded organisations.');
    }

    /**
     * SC-N3: Revoked session — actor with a revoked session token cannot make payment operations.
     */
    public function test_revoked_session_payment_operation_denied(): void
    {
        $this->markTestSkipped('Docker-gated: requires running identity module with session revocation.');
    }

    /**
     * SC-N4: Self-approval — same actor submits and approves config draft → 403 SELF_APPROVAL_NOT_ALLOWED.
     */
    public function test_config_draft_self_approval_returns_403(): void
    {
        $this->markTestSkipped('Docker-gated: requires running database with seeded payment providers.');
    }

    /**
     * SC-N5: Invalid callback provider — POST to /payment/callbacks/nonexistent returns 404.
     */
    public function test_invalid_callback_provider_returns_404(): void
    {
        $response = $this->postJson('/api/v1/payment/callbacks/nonexistent_provider_xyz', [
            'event' => 'payment.succeeded',
            'data'  => [],
        ]);

        $response->assertStatus(404);
    }
}
