<?php

declare(strict_types=1);

return [
    'hmac_key' => env('AUDIT_HMAC_KEY', ''),

    'per_page' => 20,

    'report' => [
        'async_threshold_days' => 1,
    ],
];
