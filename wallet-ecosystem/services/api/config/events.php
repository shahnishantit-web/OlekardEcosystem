<?php

declare(strict_types=1);

return [
    // Explicit opt-in for local paid-event checkout fixtures. Never use for real money.
    'local_demo_pricing_enabled' => (bool) env('EVENTS_LOCAL_DEMO_PRICING_ENABLED', false),
    // Synthetic, narrowly scoped Payment consumer identity for the local demo only.
    'local_demo_payment_fulfilment_enabled' => (bool) env('EVENTS_LOCAL_DEMO_PAYMENT_FULFILMENT_ENABLED', false),
    // Full POC wiring is opt-in and only bound by EventsServiceProvider in APP_ENV=local.
    'poc_enabled' => (bool) env('EVENTS_POC_ENABLED', false),
    'poc_demo_sponsor_enabled' => (bool) env('EVENTS_POC_DEMO_SPONSORS_ENABLED', false),
    'poc_demo_notifications_enabled' => (bool) env('EVENTS_POC_DEMO_NOTIFICATIONS_ENABLED', false),
];
