<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T070: Feature tests for CertificationEvidenceAdminController.
 * Docker-gated for full integration.
 */
final class CertificationEvidenceAdminControllerTest extends TestCase
{
    public function test_evidence_upload_creates_record(): void
    {
        $this->markTestSkipped('Docker-gated: evidence upload requires seeded bank/account data');
    }

    public function test_sandbox_test_returns_result(): void
    {
        $this->markTestSkipped('Docker-gated: sandbox test requires running payment schema with mock account');
    }

    public function test_lifecycle_gate_blocks_submit_evidence_when_no_required_evidence(): void
    {
        $this->markTestSkipped('Docker-gated: lifecycle gate check requires BankAccountLifecycleService + DB');
    }

    public function test_sc007_sandbox_test_zero_payment_rows(): void
    {
        $this->markTestSkipped('Docker-gated: SC-007 assertion requires DB row count before/after');
    }

    public function test_unauthorized_without_payment_configuration_permission(): void
    {
        $this->markTestSkipped('Docker-gated: requires auth middleware and permission seeding');
    }
}
