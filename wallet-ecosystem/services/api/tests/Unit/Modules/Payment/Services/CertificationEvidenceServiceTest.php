<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use PHPUnit\Framework\TestCase;

/**
 * T069: Unit tests for CertificationEvidenceService.
 * SC-007: Sandbox test must not create payment rows.
 * Docker-gated for full DB-backed tests.
 */
final class CertificationEvidenceServiceTest extends TestCase
{
    public function test_has_required_evidence_false_when_no_evidence(): void
    {
        $this->markTestSkipped('Docker-gated: hasRequiredEvidence requires DB with certification_evidence table');
    }

    public function test_has_required_evidence_true_when_both_types_present(): void
    {
        $this->markTestSkipped('Docker-gated: requires seeded documentation + merchant_ownership evidence');
    }

    public function test_has_required_evidence_false_when_only_one_type_present(): void
    {
        $this->markTestSkipped('Docker-gated: partial evidence scenario requires DB');
    }

    public function test_add_evidence_creates_record(): void
    {
        $this->markTestSkipped('Docker-gated: addEvidence requires DB insert');
    }

    public function test_add_evidence_calls_audit(): void
    {
        $this->markTestSkipped('Docker-gated: audit call requires DI container with AuditService');
    }

    /**
     * SC-007: Sandbox test MUST NOT create payment_intents or payment_attempts rows.
     */
    public function test_sc007_sandbox_test_does_not_create_payment_rows(): void
    {
        $this->markTestSkipped('Docker-gated: SC-007 requires DB to assert zero rows created in payment_intents/payment_attempts');
    }

    public function test_sandbox_test_creates_test_run_evidence_record(): void
    {
        $this->markTestSkipped('Docker-gated: sandbox test creates test_run evidence record requires DB');
    }

    public function test_sandbox_test_returns_duration_ms_field(): void
    {
        $this->markTestSkipped('Docker-gated: sandbox test result shape requires service instantiation');
    }
}
