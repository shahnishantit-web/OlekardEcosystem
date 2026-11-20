<?php

declare(strict_types=1);

return [
    // Fail-closed fallback when the admin-managed platform setting is absent.
    // Production remains locked in FuneralFeatureReadiness until release approval.
    'http_enabled' => false,
    'default_amount_minor' => (int) env('COMMUNITY_FUNERAL_DEFAULT_AMOUNT_MINOR', 2000),
    'default_currency' => env('COMMUNITY_FUNERAL_DEFAULT_CURRENCY', 'BWP'),
];
