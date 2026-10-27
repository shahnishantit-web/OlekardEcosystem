<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T097: Two-person approval enforcement — SC-003.
 * approved_by ≠ submitted_by, enforced 100% of the time at service layer.
 * Docker-gated for full integration.
 */
final class TwoPersonApprovalTest extends TestCase
{
    public function test_sc003_submitter_cannot_approve_own_submission(): void
    {
        $this->markTestSkipped('Docker-gated: requires seeded admin user + account in EvidenceReview + lifecycle endpoint');
    }

    public function test_sc003_approval_conflict_returns_409(): void
    {
        $this->markTestSkipped('Docker-gated: requires lifecycle transition POST to return 409 approval_conflict');
    }

    public function test_sc003_different_actor_can_approve(): void
    {
        $this->markTestSkipped('Docker-gated: requires two distinct admin user tokens');
    }
}
