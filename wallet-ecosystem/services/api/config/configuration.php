<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration Change Request Escalation Threshold
    |--------------------------------------------------------------------------
    | Pending change requests older than this many hours trigger an alert-level
    | audit event via the configuration:escalation-check command (FR-010).
    */
    'escalation_threshold_hours' => (int) env('CONFIGURATION_ESCALATION_THRESHOLD_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Maximum History Records Per Page
    |--------------------------------------------------------------------------
    | Maximum number of version history records returned per paginated page
    | on the configuration history API (FR-017).
    */
    'max_history_per_page' => (int) env('CONFIGURATION_MAX_HISTORY_PER_PAGE', 50),
];
