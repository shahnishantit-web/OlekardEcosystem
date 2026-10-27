<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Callback Processing
    |--------------------------------------------------------------------------
    */

    'live_callback_processing_enabled' => env('PAYMENT_LIVE_CALLBACK_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Pending Ledger Config Providers
    |--------------------------------------------------------------------------
    | Providers whose payments should use the pending-accounting path instead of
    | the immediate ledger post path.
    */

    'pending_ledger_config_providers' => [],

    /*
    |--------------------------------------------------------------------------
    | Mock Bank Adapter (dev/test only)
    |--------------------------------------------------------------------------
    */

    'mock_bank' => [
        'default_outcome'       => env('PAYMENT_MOCK_BANK_OUTCOME', 'confirmed'),
        'artificial_delay_ms'   => (int) env('PAYMENT_MOCK_BANK_DELAY_MS', 0),
        'failure_rate'          => (float) env('PAYMENT_MOCK_BANK_FAILURE_RATE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payout
    |--------------------------------------------------------------------------
    */

    'payout' => [
        'reservation_expiry_hours' => (int) env('PAYMENT_PAYOUT_RESERVATION_EXPIRY_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | P19: Receipt Download Tokens
    |--------------------------------------------------------------------------
    | Single-use, expiring tokens issued for authorised receipt downloads.
    | Tokens are stored in DB (not cache) to support revocation auditing.
    */

    'receipt' => [
        'download_token_ttl_minutes' => (int) env('PAYMENT_RECEIPT_DOWNLOAD_TOKEN_TTL_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | P19: Payment Export Tokens
    |--------------------------------------------------------------------------
    */

    'export' => [
        'token_ttl_minutes' => (int) env('PAYMENT_EXPORT_TOKEN_TTL_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | P19: Domain Deep Links
    |--------------------------------------------------------------------------
    | purpose_type slugs listed here will return an "unavailable" object
    | instead of a live deep link in the payment history API response.
    */

    'deep_link' => [
        'retired_purposes' => array_filter(
            explode(',', env('PAYMENT_DEEP_LINK_RETIRED_PURPOSES', '')),
        ),
    ],

];
