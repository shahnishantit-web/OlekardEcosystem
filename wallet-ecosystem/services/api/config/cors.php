<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| The API routes are served under /v1/* (no /api prefix — see routes/api.php
| and bootstrap/app.php). The framework default only covers `api/*`, so the
| browser preflight for the Admin/Web SPA got no CORS headers and failed.
|
| Allowed origins are env-driven (CORS_ALLOWED_ORIGINS, comma-separated) so
| production can lock this down without a code change.
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3001',
    ]))),
)));

return [

    'paths' => [
        'v1/*',
        'api/v1/*',
        'health',
        'ready',
        'startup',
        'up',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-ID'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
