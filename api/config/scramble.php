<?php

declare(strict_types=1);

return [
    /*
     * API version shown in the documentation.
     */
    'api_version' => '1.0.0',

    /*
     * URL path for the API docs UI.
     */
    'api_path' => 'api/docs',

    /*
     * The path prefix that all API routes share — used to auto-discover routes.
     */
    'api_domain' => null,

    /*
     * Restrict doc access in production (set to false to open public).
     */
    'middleware' => ['web'],

    /*
     * Base path of routes to include.
     */
    'info' => [
        'title'       => 'ETHR API',
        'description' => 'Ethiopian Workforce Operating System — REST API v1. All endpoints require Bearer token authentication unless noted as public.',
        'version'     => env('APP_VERSION', '1.0.0'),
    ],

    /*
     * Auth scheme shown in docs.
     */
    'security' => [
        [
            'bearerAuth' => [],
        ],
    ],

    'securitySchemes' => [
        'bearerAuth' => [
            'type'         => 'http',
            'scheme'       => 'bearer',
            'bearerFormat' => 'Sanctum',
        ],
    ],

    'extensions' => [],
];
