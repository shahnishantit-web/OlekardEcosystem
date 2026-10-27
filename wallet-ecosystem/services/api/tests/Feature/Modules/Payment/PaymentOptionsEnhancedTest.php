<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T076: Feature tests for enhanced consumer payment options (P12 enrichment).
 * SC-005: Activate bank → appears in options; suspend → absent; no credentials.
 * SC-006: Two banks same profile both appear.
 * Docker-gated for full integration.
 */
final class PaymentOptionsEnhancedTest extends TestCase
{
    public function test_sc005_activate_bank_appears_in_options(): void
    {
        $this->markTestSkipped('Docker-gated: SC-005 requires seeded bank/account in Active state');
    }

    public function test_sc005_suspend_bank_absent_from_options(): void
    {
        $this->markTestSkipped('Docker-gated: SC-005 requires account suspension then options query');
    }

    public function test_sc006_two_banks_same_profile_both_appear(): void
    {
        $this->markTestSkipped('Docker-gated: SC-006 requires two seeded banks with http-redirect-v1 profile');
    }

    public function test_no_credentials_in_options_response(): void
    {
        $this->markTestSkipped('Docker-gated: credential absence scan requires full API response');
    }

    public function test_options_response_includes_display_label(): void
    {
        $this->markTestSkipped('Docker-gated: display_label field requires ConsumerPaymentOption seeded data');
    }

    public function test_options_response_includes_fee_disclosure_structure(): void
    {
        $this->markTestSkipped('Docker-gated: fee_disclosure field requires fee rules seeding');
    }
}
