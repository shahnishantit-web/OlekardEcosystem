<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation Expiry
    |--------------------------------------------------------------------------
    | Number of hours after which a staff invitation token expires.
    */
    'invitation_expiry_hours' => (int) env('COMMUNITY_INVITATION_EXPIRY_HOURS', 168), // 7 days

    /*
    |--------------------------------------------------------------------------
    | Webhook Freshness Window
    |--------------------------------------------------------------------------
    | Maximum age in seconds that a webhook timestamp is accepted.
    | Requests with a timestamp older than this value are rejected.
    */
    'webhook_freshness_seconds' => (int) env('COMMUNITY_WEBHOOK_FRESHNESS_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Export Link Expiry
    |--------------------------------------------------------------------------
    | Number of hours after which an async export download link expires.
    */
    'export_link_expiry_hours' => (int) env('COMMUNITY_EXPORT_LINK_EXPIRY_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Malware Scan
    |--------------------------------------------------------------------------
    | Enable/disable malware scanning for uploaded evidence files.
    | When disabled, evidence files skip the scan queue (dev/test only).
    */
    'malware_scan_enabled' => (bool) env('COMMUNITY_MALWARE_SCAN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Formula Injection Escape Character
    |--------------------------------------------------------------------------
    | Prefix prepended to CSV cell values that begin with = + - @ to prevent
    | spreadsheet formula injection.
    */
    'csv_formula_escape_char' => env('COMMUNITY_CSV_FORMULA_ESCAPE_CHAR', "'"),

    /*
    |--------------------------------------------------------------------------
    | Step-Up Approval Threshold
    |--------------------------------------------------------------------------
    | Amount in minor units (integer) above which a refund or payout change
    | requires an additional independent approval via ApprovalService.
    | Defaults to 100,000 BWP minor units (1,000.00 BWP).
    */
    'step_up_threshold_minor' => (int) env('COMMUNITY_STEP_UP_THRESHOLD_MINOR', 100000),

    /*
    |--------------------------------------------------------------------------
    | Evidence Upload Constraints
    |--------------------------------------------------------------------------
    */
    'evidence_max_size_kb'   => (int) env('COMMUNITY_EVIDENCE_MAX_SIZE_KB', 10240), // 10 MB
    'evidence_allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],

    /*
    |--------------------------------------------------------------------------
    | Refund Policy Defaults
    |--------------------------------------------------------------------------
    | Default eligibility window in days from purchase date.
    */
    'default_refund_window_days' => (int) env('COMMUNITY_DEFAULT_REFUND_WINDOW_DAYS', 30),

];
