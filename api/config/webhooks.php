<?php

declare(strict_types=1);

return [
    /*
    | Allow webhook URLs on this machine or the private network.
    |
    | For local development and tests only: a webhook receiver running on the
    | developer's machine (http://127.0.0.1:…) is refused by ExternalUrl and by
    | DispatchWebhookJob, which is right everywhere else. This switch is
    | honoured ONLY when APP_ENV is `local` or `testing` — in production it is
    | ignored, so a stray .env line cannot open SSRF. See
    | App\Support\WebhookTargets.
    */
    'allow_private_hosts' => (bool) env('WEBHOOK_ALLOW_PRIVATE_HOSTS', false),
];
