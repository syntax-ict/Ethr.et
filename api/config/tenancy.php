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

];
