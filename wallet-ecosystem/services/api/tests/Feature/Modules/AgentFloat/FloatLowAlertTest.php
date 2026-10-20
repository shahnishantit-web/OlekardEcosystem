<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AgentFloat;

use Tests\TestCase;

class FloatLowAlertTest extends TestCase
{
    public function test_low_float_alert_event_fired_when_balance_drops_below_threshold(): void
    {
        // Fund float to BWP 100; set threshold BWP 200; perform cash-in BWP 50
        // → FloatLowAlertEvent dispatched, low_float_alerted=true
        $this->markTestIncomplete('Listen for FloatLowAlertEvent; assert low_float_alerted=true in float_limits.');
    }

    public function test_alert_not_re_fired_when_already_alerted(): void
    {
        // low_float_alerted=true; perform another cash-in → no second FloatLowAlertEvent
        $this->markTestIncomplete('Assert event dispatched exactly once across two cash-ins.');
    }

    public function test_alert_resets_when_balance_recovers_via_funding(): void
    {
        // low_float_alerted=true; fund float above threshold → low_float_alerted=false
        $this->markTestIncomplete('Requires funded float wallet below threshold + fund-top-up.');
    }

    public function test_alert_resets_when_balance_recovers_via_cash_out(): void
    {
        // low_float_alerted=true; cash-out pushes float above threshold → low_float_alerted=false (G1)
        $this->markTestIncomplete('Requires cash-out that increases agent float above threshold.');
    }

    public function test_no_alert_when_limits_not_configured(): void
    {
        // No float_limits row → FloatAlertService is a no-op
        $this->markTestIncomplete('Omit float_limits seeding; verify no event dispatched.');
    }
}
