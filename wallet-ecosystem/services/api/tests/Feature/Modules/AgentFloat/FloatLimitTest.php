<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatLimitTest extends TestCase
{
    public function test_admin_can_update_float_limits(): void
    {
        // PATCH limits with per_transaction_limit and low_float_threshold → 200; is_tbd toggled false
        $this->markTestIncomplete('Requires agent with float wallet and admin token.');
    }

    public function test_update_clears_is_tbd_only_for_supplied_fields(): void
    {
        // Partial update: only per_transaction_limit supplied → that field is_tbd=false; others unchanged
        $this->markTestIncomplete('Read FloatLimitModel after partial PATCH and assert per-field is_tbd state.');
    }

    public function test_admin_can_view_float_limits(): void
    {
        // GET limits → 200 with all fields (null for TBD)
        $this->markTestIncomplete('Requires agent with float_limits record.');
    }

    public function test_limits_view_returns_null_for_tbd_fields(): void
    {
        // FloatLimitResource returns null for any field where is_tbd=true
        $this->markTestIncomplete('Create float_limits with all is_tbd=true and verify JSON nulls.');
    }

    public function test_update_with_invalid_values_returns_422(): void
    {
        // per_transaction_limit=-1 → VALIDATION_ERROR
        $this->markTestIncomplete('Send negative integer in PATCH body.');
    }

    public function test_unauthorized_returns_403(): void
    {
        // Agent token without limits.manage → 403
        $this->markTestIncomplete('Wire up agent token without agent_float.limits.manage permission.');
    }
}
