<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * Returns a standardized PostgreSQL connection array for a given schema.
 * All 9 module connections use the same host/database in monolith phase.
 * Extraction to a separate service = change the DB_HOST env var per module.
 *
 * @param string $schema The PostgreSQL schema name (equals the module name).
 */
if (! function_exists('pgsql_config')) {
function pgsql_config(string $schema): array
{
    return [
        'driver'         => 'pgsql',
        'host'           => env('DB_HOST', '127.0.0.1'),
        'port'           => env('DB_PORT', '5432'),
        'database'       => env('DB_DATABASE', 'wallet_ecosystem'),
        'username'       => env('DB_USERNAME', 'wallet_app'),
        'password'       => env('DB_PASSWORD', ''),
        'charset'        => 'utf8',
        'prefix'         => '',
        'prefix_indexes' => true,
        'search_path'    => $schema,
        'sslmode'        => env('DB_SSLMODE', 'prefer'),
        'strict'         => true,
        'timezone'       => 'UTC',
    ];
}
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | The platform connection is the default. All module repositories MUST
    | specify their own named connection explicitly (FR-186). Never rely on
    | the default for business logic.
    |
    */

    'default' => env('DB_CONNECTION', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections — one per module schema (FR-014, FR-186)
    |--------------------------------------------------------------------------
    |
    | Each module owns exactly one PostgreSQL schema. Repositories MUST use
    | their module's named connection. Cross-schema JOINs are PROHIBITED
    | (enforced by Pest architecture tests — T061, T123).
    |
    */

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DB_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout'            => null,
            'journal_mode'            => null,
            'synchronous'             => null,
        ],

        // Cross-cutting infrastructure: audit_events, idempotency_records, outbox_events, configurations
        'platform'     => pgsql_config('platform'),

        // Business domain modules — stub schemas in Platform Foundation; implemented in E01–E11
        'identity'     => pgsql_config('identity'),
        'profile'      => pgsql_config('profile'),
        'wallet'       => pgsql_config('wallet'),
        'ledger'       => pgsql_config('ledger'),
        'kyc'          => pgsql_config('kyc'),
        'transaction'  => pgsql_config('transaction'),
        'risk'         => pgsql_config('risk'),
        'agent'        => pgsql_config('agent'),
        'notification' => pgsql_config('notification'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is used for queue, cache, and idempotency acceleration.
    | Redis is NEVER the source of financial truth (FR-057).
    | Separate databases per purpose to allow independent eviction policies (TBD).
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        'idempotency' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_IDEMPOTENCY_DB', '2'),
        ],

        // E01: session revocation, JWKS cache, OTP rate limiting, login rate limiting
        'identity' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_IDENTITY_DB', '3'),
        ],

    ],

];
