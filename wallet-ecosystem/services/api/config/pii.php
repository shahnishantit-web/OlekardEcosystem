<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PII Retention Periods
    |--------------------------------------------------------------------------
    |
    | Configures how many days before a PII record in each category is eligible
    | for anonymization or deletion by the app:purge-expired-pii command.
    | A value of 0 disables purging for that category (safe default).
    |
    | Production: set these via environment variables or override through the
    | Platform Configuration service.  Retention periods are a compliance
    | requirement governed by Constitution Principle 3.
    |
    */

    'retention_days' => [
        'identity'    => (int) env('PII_RETENTION_DAYS_IDENTITY', 0),
        'kyc'         => (int) env('PII_RETENTION_DAYS_KYC', 0),
        'transaction' => (int) env('PII_RETENTION_DAYS_TRANSACTION', 0),
    ],

];
