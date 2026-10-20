<?php

declare(strict_types=1);

use App\Modules\Ops\Infrastructure\Logging\OpsLogChannelFactory;
use App\Modules\Platform\Infrastructure\Logging\SensitiveDataProcessor;
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
            'processors' => [SensitiveDataProcessor::class],
        ],

        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', env('LOG_STACK', 'stderr,ops_database')),
            'ignore_exceptions' => false,
        ],

        /*
        | E30: structured logs persisted to ops.log_entries (FR-001).
        | Uses OpsLogChannelFactory for container-injected DatabaseLogHandler.
        | Enable via LOG_CHANNEL=stack or LOG_CHANNEL=ops_database.
        */
        'ops_database' => [
            'driver' => 'custom',
            'via'    => OpsLogChannelFactory::class,
            'level'  => env('OPS_LOG_LEVEL', 'info'),
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'processors' => [SensitiveDataProcessor::class],
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
