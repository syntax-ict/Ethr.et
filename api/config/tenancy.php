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
    | either way. This only decides which URL ETHR hands out.
    */

    'subdomains' => (bool) env('TENANCY_SUBDOMAINS', false),

];
