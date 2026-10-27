<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T098: Credential absence scan across all API responses — SC-004.
 * Docker-gated for full integration.
 */
final class CredentialAbsenceTest extends TestCase
{
    public function test_sc004_account_detail_has_no_raw_secrets(): void
    {
        $this->markTestSkipped('Docker-gated: requires GET account response with known key names to scan');
    }

    public function test_sc004_operation_config_has_no_raw_secrets(): void
    {
        $this->markTestSkipped('Docker-gated: requires GET operations response with secret_key_references');
    }

    public function test_sc004_options_response_has_no_raw_secrets(): void
    {
        $this->markTestSkipped('Docker-gated: requires GET payment options response scan');
    }

    public function test_sc004_no_bearer_pattern_in_any_response(): void
    {
        $this->markTestSkipped('Docker-gated: full response body scan requires running API');
    }
}
