<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Tests\TestCase;

/**
 * P11 causation threading from listener to child events is deferred to P12.
 * P12 will require InProcessDriver to pass the outbox row ID to the listener
 * so the listener can set causation_id on the child event it publishes.
 */
final class OutboxCausationDocumentationTest extends TestCase
{
    public function test_payment_confirmed_root_event_has_null_causation_id(): void
    {
        $this->markTestSkipped(
            'T018/P11: listener-to-child causation ID threading deferred to P12 — '
            . 'requires InProcessDriver to pass outbox row ID to listener; '
            . 'see research.md Decision 6 in 060-outbox-inbox-delivery spec package. '
            . 'Expected test body: trigger a payment confirmation → assert '
            . 'platform.outbox_events causation_id IS NULL for root event; '
            . 'P12 will assert future child events carry causation_id = parent outbox row id.'
        );
    }
}
