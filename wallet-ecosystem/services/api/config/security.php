<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security Configuration — E26
|--------------------------------------------------------------------------
|
| HTTP security header values and CSP policy. Override via environment
| variables for deployment-specific requirements without code changes.
|
*/

return [

    /*
    | Content-Security-Policy header value.
    | Default blocks all origins except self and disallows framing.
    */
    'csp_policy' => env('CSP_POLICY', "default-src 'self'; frame-ancestors 'none'"),

    /*
    | Permissions-Policy header value.
    | Default disables camera, microphone, and payment APIs.
    */
    'permissions_policy' => env('PERMISSIONS_POLICY', 'camera=(), microphone=(), payment=()'),

    /*
    | HSTS max-age in seconds. 1 year = 31 536 000.
    */
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

];
