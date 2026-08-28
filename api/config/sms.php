<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default SMS driver
    |--------------------------------------------------------------------------
    |
    | `log` writes messages to the application log and reports itself unavailable,
    | which is what keeps the preferences UI honest in development. `ethiotelecom`
    | posts to the operator's HTTP gateway and requires the credentials below.
    |
    */
    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Per-user daily cap
    |--------------------------------------------------------------------------
    |
    | PHASE_05 S26: "Rate limiting: max 5 SMS/day per user". SMS is the only
    | channel in the product with a per-message cost, so the cap is enforced in
    | the channel rather than left to the caller.
    |
    */
    'daily_limit_per_user' => (int) env('SMS_DAILY_LIMIT_PER_USER', 5),

    'ethiotelecom' => [
        'endpoint' => env('ETHIOTELECOM_SMS_ENDPOINT'),
        'username' => env('ETHIOTELECOM_SMS_USERNAME'),
        'password' => env('ETHIOTELECOM_SMS_PASSWORD'),
        'sender_id' => env('ETHIOTELECOM_SMS_SENDER_ID', 'ETHR'),
        'timeout' => (int) env('ETHIOTELECOM_SMS_TIMEOUT', 10),
    ],
];
