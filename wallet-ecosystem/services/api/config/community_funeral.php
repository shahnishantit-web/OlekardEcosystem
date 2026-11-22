<?php

declare(strict_types=1);

return [
    // Fail-closed fallback when the admin-managed platform setting is absent.
    // Production remains locked in FuneralFeatureReadiness until release approval.
    'http_enabled' => false,
    'default_amount_minor' => (int) env('COMMUNITY_FUNERAL_DEFAULT_AMOUNT_MINOR', 2000),
    'default_currency' => env('COMMUNITY_FUNERAL_DEFAULT_CURRENCY', 'BWP'),
    'customer_app_url' => env('CUSTOMER_APP_URL', 'http://localhost:50416'),
    'customer_app_hash_routes' => (bool) env('CUSTOMER_APP_HASH_ROUTES', true),
];
