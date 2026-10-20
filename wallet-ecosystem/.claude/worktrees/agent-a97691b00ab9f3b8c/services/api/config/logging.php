<?php

declare(strict_types=1);

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

/*
|--------------------------------------------------------------------------
| Logging Configuration
|--------------------------------------------------------------------------
|
| All production logging uses structured JSON to stderr (FR-085, FR-086).
| Fields: timestamp, level, message, correlation_id, request_id, actor_id,
|         module, context.
| Correlation ID is injected by CorrelationIdMiddleware (T045).
|
*/

return [

    'default' => env('LOG_CHANNEL', 'stderr'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        /*
        | Primary channel — structured JSON to stderr.
        | JSON formatter writes one JSON object per line for log aggregation.
        */
        'stderr' => [
            'driver'    => 'monolog',
            'level'     => env('LOG_LEVEL', 'info'),
            'handler'   => StreamHandler::class,
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'batchMode'          => JsonFormatter::BATCH_MODE_NEWLINES,
                'appendNewline'      => true,
                'ignoreEmptyContextAndExtra' => false,
            ],
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', env('LOG_STACK', 'stderr')),
            'ignore_exceptions' => false,
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path'  => storage_path('logs/laravel.log'),
        ],

    ],

];
