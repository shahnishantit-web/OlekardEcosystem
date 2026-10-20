<?php

declare(strict_types=1);

return [
    'evidence' => [
        'disk'          => env('SUPPORT_EVIDENCE_DISK', 'support-evidence'),
        'max_files'     => (int) env('SUPPORT_EVIDENCE_MAX_FILES', 5),
        'max_size_mb'   => (int) env('SUPPORT_EVIDENCE_MAX_SIZE_MB', 10),
        'signed_url_ttl_minutes' => (int) env('SUPPORT_EVIDENCE_SIGNED_URL_TTL', 15),
    ],

    'tickets' => [
        'default_per_page' => (int) env('SUPPORT_TICKETS_PER_PAGE', 20),
    ],

    'disputes' => [
        'default_per_page' => (int) env('SUPPORT_DISPUTES_PER_PAGE', 20),
    ],

    'dispute' => [
        'approve' => [
            'require_second_approval' => (bool) env('SUPPORT_DISPUTE_APPROVE_MAKER_CHECKER', false),
        ],
        'refund' => [
            'require_second_approval' => (bool) env('SUPPORT_DISPUTE_REFUND_MAKER_CHECKER', false),
        ],
    ],
];
