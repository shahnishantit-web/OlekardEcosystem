<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Horizon Configuration
|--------------------------------------------------------------------------
|
| Queue naming convention: {module}.{priority}
| Examples: platform.critical, identity.high, wallet.default, ledger.low
|
| Supervisor naming mirrors worker purpose so dashboards are readable.
|
*/

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => env('HORIZON_MEMORY_LIMIT', 128),

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue'      => ['critical', 'high', 'default', 'low'],
            'balance'    => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'balanceMaxShift'  => 1,
            'balanceCooldown'  => 3,
            'autoScalingStrategy' => 'time',
            'tries'    => 3,
            'timeout'  => 60,
            'sleep'    => 3,
        ],

        'supervisor-outbox' => [
            'connection' => 'redis',
            'queue'      => ['outbox'],
            'balance'    => 'simple',
            'processes'  => 3,
            'tries'      => 1,
            'timeout'    => 60,
            'sleep'      => 1,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-default' => [
                'minProcesses' => 2,
                'maxProcesses' => 10,
            ],
            'supervisor-outbox' => [
                'processes' => 3,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-outbox' => [
                'processes' => 1,
            ],
        ],

    ],

];
