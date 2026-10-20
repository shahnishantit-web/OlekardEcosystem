<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Wallet Reference Format
    |--------------------------------------------------------------------------
    | TBD-E06-007 — format string for WalletReferenceGenerator.
    | null = use built-in default: WLT-{YYYYMMDD}-{8 alphanum}
    */
    'reference_format' => env('WALLET_REFERENCE_FORMAT', null),

    /*
    |--------------------------------------------------------------------------
    | Expire Holds Schedule
    |--------------------------------------------------------------------------
    | TBD-E06-001 — cron expression or null (disables scheduling).
    | null = command must be triggered manually or via queue.
    */
    'expire_holds_schedule' => env('WALLET_EXPIRE_HOLDS_SCHEDULE', null),

    /*
    |--------------------------------------------------------------------------
    | Pocket Creation Strategy
    |--------------------------------------------------------------------------
    | TBD-E06-008 — 'lazy' (on first use) or 'eager' (all at wallet creation).
    */
    'pocket_creation_strategy' => env('WALLET_POCKET_CREATION_STRATEGY', 'lazy'),

    /*
    |--------------------------------------------------------------------------
    | Stub Ledger Balance
    |--------------------------------------------------------------------------
    | Pre-E07 stub mode. When true, balance queries return ledger_balance=0
    | with meta.ledger_unavailable=true rather than calling the real LedgerModule.
    | Set false in production once E07 is deployed.
    */
    'stub_ledger_balance' => (bool) env('WALLET_STUB_LEDGER_BALANCE', false),

    /*
    |--------------------------------------------------------------------------
    | Show Pending Meta
    |--------------------------------------------------------------------------
    | When true, balance responses include a pending_balance field sourced
    | from in-flight ledger postings. Requires E07 ledger integration.
    */
    'show_pending_meta' => (bool) env('WALLET_SHOW_PENDING_META', false),
];
