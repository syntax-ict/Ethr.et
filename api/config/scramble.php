<?php

declare(strict_types=1);
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * API version shown in the documentation.
     */
    'api_version' => '1.0.0',

    // Listed first in the generated document so an integrator copying the top
    // server URL gets production, not whatever APP_URL happens to be locally.
    // Unset in development, where only the local server is listed.
    'production_url' => env('SCRAMBLE_PRODUCTION_URL'),

    /*
     * URL path for the API docs UI.
     */
    'api_path' => 'api/v1',

    /*
     * The path prefix that all API routes share — used to auto-discover routes.
     */
    'api_domain' => null,

    /*
     * Restrict doc access in production.
     *
     * RestrictedDocsAccess is load-bearing and must not be dropped: it lets any
     * caller through in the `local` environment, and everywhere else requires the
     * `viewApiDocs` gate (defined in AppServiceProvider — super admins only),
     * returning 403 otherwise.
     *
     * Without it this list is just ['web'], which authenticates nobody, and
     * GET /api/docs anonymously serves the complete API map — every operation
     * with its request and response schemas — to the public internet.
     */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    /*
     * Base path of routes to include.
     */
    'info' => [
        'title' => 'ETHR API',
        'description' => 'Ethiopian Workforce Operating System — REST API v1. All endpoints require Bearer token authentication unless noted as public.',
        'version' => env('APP_VERSION', '1.0.0'),
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
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
        ],
    ],

    'extensions' => [],
];
