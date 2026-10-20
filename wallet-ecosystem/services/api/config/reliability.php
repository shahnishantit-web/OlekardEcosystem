<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | E27 Reliability & Transaction Integrity — Configuration
    |--------------------------------------------------------------------------
    | All values are env-var backed and can be overridden via
    | Platform Configuration service (ConfigurationServiceInterface).
    */

    'transaction_timeout_seconds' => (int) env('RELIABILITY_TRANSACTION_TIMEOUT_SECONDS', 30),

    'orphan_threshold_minutes' => (int) env('RELIABILITY_ORPHAN_THRESHOLD_MINUTES', 5),

    'max_retry_attempts' => (int) env('RELIABILITY_MAX_RETRY_ATTEMPTS', 3),

    'retry_base_delay_seconds' => (int) env('RELIABILITY_RETRY_BASE_DELAY_SECONDS', 5),

    'dead_letter_ttl_days' => (int) env('RELIABILITY_DEAD_LETTER_TTL_DAYS', 30),

    'idempotency_ttl_days' => (int) env('RELIABILITY_IDEMPOTENCY_TTL_DAYS', 7),

    'ledger_scan_interval_minutes' => (int) env('RELIABILITY_LEDGER_SCAN_INTERVAL_MINUTES', 60),
];
