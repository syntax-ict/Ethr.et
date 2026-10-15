<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Installation mode
    |--------------------------------------------------------------------------
    |
    | 'multi'  — the SaaS deployment: many organisations, self-service
    |            registration, tenants resolved from the host, the entry URL
    |            or the X-Tenant header. The default, and what ethr.et runs.
    |
    | 'single' — a single-tenant install (on-prem): the deployment serves
    |            exactly one organisation, resolved on every request without
    |            a host, slug or header, and self-service registration closes
    |            once that organisation exists. docs/ON-PREM.md is the guide.
    |
    | Any other value behaves as 'multi' — InstallMode::single() is a strict
    | comparison, so a typo cannot half-enable the mode.
    |
    */

    'mode' => env('ETHR_MODE', 'multi'),

    /*
    |--------------------------------------------------------------------------
    | The organisation a single-tenant install serves
    |--------------------------------------------------------------------------
    |
    | Its slug (tenants.subdomain). Recommended on every single install; when
    | left empty, a sole organisation resolves by itself and anything else
    | fails closed. A slug that names no organisation answers 404 on every
    | request — a misconfigured install must fail at smoke-test time, not
    | serve empty pages for weeks.
    |
    */

    'single_tenant' => env('ETHR_SINGLE_TENANT'),

];
