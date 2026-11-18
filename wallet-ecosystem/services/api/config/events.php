<?php

declare(strict_types=1);

return [
    // Explicit opt-in for local paid-event checkout fixtures. Never use for real money.
    'local_demo_pricing_enabled' => (bool) env('EVENTS_LOCAL_DEMO_PRICING_ENABLED', false),
    // Synthetic, narrowly scoped Payment consumer identity for the local demo only.
    'local_demo_payment_fulfilment_enabled' => (bool) env('EVENTS_LOCAL_DEMO_PAYMENT_FULFILMENT_ENABLED', false),
];
