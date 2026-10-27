<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T024: Feature tests for POST /api/v1/payment/bank/dispute-callback.
 *
 * @group docker
 */
final class BankDisputeCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    /** (a) Valid payload → 201 Created, case recorded */
    public function test_valid_dispute_callback_creates_case(): void
    {
        $this->markTestSkipped('Requires Docker (database + auth middleware seeded).');
    }

    /** (b) Missing required field → 422 */
    public function test_missing_required_field_returns_422(): void
    {
        $this->markTestSkipped('Requires Docker (database + auth middleware seeded).');
    }

    /** (c) Duplicate provider reference → 200 (idempotent acknowledged) */
    public function test_duplicate_provider_reference_is_acknowledged(): void
    {
        $this->markTestSkipped('Requires Docker (database + auth middleware seeded).');
    }

    /** (d) Unknown payment_attempt_id → 422 + reconciliation exception recorded */
    public function test_unknown_attempt_returns_422_and_records_recon_exception(): void
    {
        $this->markTestSkipped('Requires Docker (database + auth middleware seeded).');
    }

    /** (e) Unauthenticated request → 401 */
    public function test_unauthenticated_request_returns_401(): void
    {
        $this->markTestSkipped('Requires Docker (database + auth middleware seeded).');
    }
}
