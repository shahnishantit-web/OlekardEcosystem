<?php

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => [
                'title' => 'Wallet Ecosystem API',
            ],

            'routes' => [
                /*
                 * Route for accessing API documentation UI (FR-148: http://localhost/api/docs)
                 */
                'api' => 'api/docs',
            ],
            'paths' => [
                'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),

                'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),

                /*
                 * File name of the generated JSON documentation file
                 */
                'docs_json' => 'openapi.json',

                'docs_yaml' => 'openapi.yaml',

                'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),

                /*
                 * Absolute paths to directories containing OpenAPI PHPDoc annotations
                 */
                'annotations' => [
                    app_path(),
                ],
            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            /*
             * Route for accessing parsed OpenAPI JSON directly
             */
            'docs' => 'docs',

            'oauth2_callback' => 'api/oauth2-callback',

            'middleware' => [
                /*
                 * API documentation UI is restricted to non-production environments.
                 * In production, access is disabled (FR-162: auto-generated; UI not exposed in prod).
                 */
                'api' => [env('APP_ENV') !== 'production' ? 'web' : 'api'],
                'asset' => [],
                'docs'  => [],
                'oauth2_callback' => [],
            ],

            'group_options' => [],
        ],

        'paths' => [
            /*
             * Absolute path where parsed annotations (openapi.json) are stored.
             * Referenced by scripts/ci/backend.sh via `php artisan l5-swagger:generate`.
             */
            'docs' => storage_path('api-docs'),

            'views' => base_path('resources/views/vendor/l5-swagger'),

            'base' => env('L5_SWAGGER_BASE_PATH', null),

            'excludes' => [],
        ],

        'scanOptions' => [
            'default_processors_configuration' => [],
            'analyser'   => null,
            'analysis'   => null,
            'processors' => [],
            'pattern'    => null,
            'exclude'    => [
                base_path('vendor'),
            ],
            'open_api_spec_version' => env(
                'L5_SWAGGER_OPEN_API_SPEC_VERSION',
                \L5Swagger\Generator::OPEN_API_DEFAULT_SPEC_VERSION,
            ),
        ],

        'securityDefinitions' => [
            'securitySchemes' => [
                'sanctum' => [
                    'type'        => 'apiKey',
                    'description' => 'Bearer token from Laravel Sanctum. Format: Bearer <token>',
                    'name'        => 'Authorization',
                    'in'          => 'header',
                ],
            ],
            'security' => [],
        ],

        /*
         * Set true only in local/development to regenerate docs on every request.
         * MUST be false in production and staging.
         */
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),

        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),

        'proxy' => false,

        'additional_config_url' => null,

        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', 'alpha'),

        'validator_url' => null,

        'ui' => [
            'display' => [
                'dark_mode'     => env('L5_SWAGGER_UI_DARK_MODE', false),
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'list'),
                'filter'        => env('L5_SWAGGER_UI_FILTERS', true),
            ],
            'authorization' => [
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', false),
                'oauth2' => [
                    'use_pkce_with_authorization_code_grant' => false,
                ],
            ],
        ],

        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', env('APP_URL', 'http://localhost')),
        ],
    ],
];
