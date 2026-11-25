<?php

return [

    /*
    | Whether this deployment serves {tenant}.<APP_DOMAIN>.
    |
    | Off by default because the production host cannot serve wildcard
    | subdomains yet (M3). While off, an organisation is reached at
    | <APP_DOMAIN>/{slug}, or at the custom domain the platform assigned it, and
    | every link ETHR emails is built that way (App\Support\FrontendUrl). Turn
    | it on only once a tenant subdomain actually answers with the application;
    | links pointing at hosts that do not resolve are worse than none.
    |
    | Resolution is unaffected: a subdomain request still resolves its tenant
    | either way. This decides which URL ETHR hands out, and where the entry
    | URL and an apex sign-in send an organisation (its canonical address).
    */

    'subdomains' => (bool) env('TENANCY_SUBDOMAINS', false),

    /*
    | The host a custom domain must be a CNAME for before it verifies.
    |
    | Empty means APP_DOMAIN itself: on shared hosting the organisation's domain
    | is added to the same site, so `hr.acme.com CNAME ethr.et` is the record.
    | Set it only when the platform publishes a dedicated target host.
    */

    'custom_domain_target' => env('TENANCY_CUSTOM_DOMAIN_TARGET'),

    /*
    | Days an organisation stays deleted before it may be erased.
    |
    | Deleting closes an organisation and keeps its records; erasing destroys
    | them (App\Services\Tenancy\OrganisationErasure, run by the owner). The
    | wait is the window the DPA gives a customer to collect its export, and
    | the time in which a deletion can still be undone. Owner decision
    | 2026-11-25: 30 days.
    */

    'erasure_grace_days' => (int) env('TENANT_ERASURE_GRACE_DAYS', 30),

];
