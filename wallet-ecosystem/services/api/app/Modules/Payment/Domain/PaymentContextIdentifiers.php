<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain;

/** Canonical context strings accepted for the P25 shared Payments contract. */
final class PaymentContextIdentifiers
{
    public const MODULE_EVENTS = 'events';

    public const PURPOSE_PAID_EVENT_TICKET = 'paid_event_ticket';

    private function __construct() {}
}
