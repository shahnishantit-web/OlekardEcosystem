<?php

declare(strict_types=1);

return [
    // Do not expose P24 routes until organisation authority governance,
    // beneficiary migration and shared payout contracts have release evidence.
    'http_enabled' => false,
    'default_amount_minor' => (int) env('COMMUNITY_FUNERAL_DEFAULT_AMOUNT_MINOR', 2000),
    'default_currency' => env('COMMUNITY_FUNERAL_DEFAULT_CURRENCY', 'BWP'),
];
