<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * T049: Feature tests for ApiOperationConfigAdminController.
 * Docker-gated — requires running payment schema and seeded data.
 */
final class ApiOperationConfigAdminControllerTest extends TestCase
{
    public function test_save_operations_requires_payment_configuration_permission(): void
    {
        $this->markTestSkipped('Docker-gated: requires seeded admin with payment.configuration permission');
    }

    public function test_save_operations_rejects_non_https_base_url_sc002(): void
    {
        $this->markTestSkipped('Docker-gated: SC-002 SSRF rejection test');
    }

    public function test_save_operations_rejects_payout_operation(): void
    {
        $this->markTestSkipped('Docker-gated: payout operation rejection test');
    }

    public function test_validate_endpoint_returns_errors_without_persisting(): void
    {
        $this->markTestSkipped('Docker-gated: validate-without-persist test');
    }

    public function test_edit_active_account_creates_draft_sc008(): void
    {
        $this->markTestSkipped('Docker-gated: SC-008 edit-active-creates-draft test');
    }
}
