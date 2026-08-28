<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | ETHR uses subdomain-based multi-tenancy: {tenant}.ethr.et
    | The frontend (Next.js) is served from the same root domain or a
    | subdomain, so we allow all *.ethr.et origins in production.
    |
    | In development, localhost:3000 is the frontend dev server.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),

    'allowed_origins_patterns' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))),

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'X-Tenant',
        'Idempotency-Key',
        'Accept',
        'Accept-Language',
        'X-XSRF-TOKEN',
        'X-CSRF-TOKEN',
        'X-ETHR-Signature',
    ],

    'exposed_headers' => [
        'Content-Disposition',
        'X-Request-Id',
    ],

    'max_age' => 86400, // 24 hours

    'supports_credentials' => true,

];
