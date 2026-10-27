<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use PHPUnit\Framework\TestCase;

/**
 * T081: Unit tests for account disable/retire eligibility.
 * Verifies suspended/retired accounts are excluded from EligibilityPipeline
 * and that existing attempt callbacks are unaffected by account status.
 */
final class DisableAccountTest extends TestCase
{
    public function test_suspended_account_is_not_eligible_for_new_attempts(): void
    {
        $account = new PaymentProviderAccountModel();
        $account->account_lifecycle_status = 'suspended';

        $this->assertFalse($account->isEligibleForNewAttempts());
    }

    public function test_retired_account_is_not_eligible_for_new_attempts(): void
    {
        $account = new PaymentProviderAccountModel();
        $account->account_lifecycle_status = 'retired';

        $this->assertFalse($account->isEligibleForNewAttempts());
    }

    public function test_active_account_is_eligible_for_new_attempts(): void
    {
        $account = new PaymentProviderAccountModel();
        $account->account_lifecycle_status = 'active';

        $this->assertTrue($account->isEligibleForNewAttempts());
    }

    public function test_draft_account_is_eligible_for_new_attempts(): void
    {
        $account = new PaymentProviderAccountModel();
        $account->account_lifecycle_status = 'draft';

        // Draft accounts are ineligible via is_active=false, not lifecycle status
        $this->assertTrue($account->isEligibleForNewAttempts());
    }

    public function test_suspended_account_excluded_from_eligibility_pipeline(): void
    {
        $this->markTestSkipped('Docker-gated: requires EligibilityPipeline + seeded suspended account');
    }

    public function test_retired_account_excluded_from_eligibility_pipeline(): void
    {
        $this->markTestSkipped('Docker-gated: requires EligibilityPipeline + seeded retired account');
    }

    public function test_existing_attempt_callback_accepted_regardless_of_account_status(): void
    {
        $this->markTestSkipped('Docker-gated: callback acceptance test requires P11 webhook processing + DB');
    }
}
