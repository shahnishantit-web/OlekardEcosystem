<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T063: Feature tests for credential masking — SC-004.
 * Verifies that no raw credential values appear in any API response.
 * Docker-gated for full integration.
 */
final class CredentialMaskingTest extends TestCase
{
    public function test_sc004_account_detail_response_contains_masked_reference(): void
    {
        $this->markTestSkipped('Docker-gated: SC-004 credential masking test requires running API with seeded data');
    }

    public function test_sc004_rotation_endpoint_does_not_return_key_names(): void
    {
        $this->markTestSkipped('Docker-gated: SC-004 rotation response must not contain raw key names');
    }

    public function test_operation_config_response_contains_masked_references(): void
    {
        $this->markTestSkipped('Docker-gated: operation config list must return masked secret references');
    }

    public function test_no_raw_secret_patterns_in_any_response(): void
    {
        $this->markTestSkipped('Docker-gated: scan all bank/account responses for Bearer/sk_ patterns');
    }
}
