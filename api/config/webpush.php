<?php

declare(strict_types=1);

/*
 * Web push (notifications on a phone or browser while ETHR is closed).
 *
 * VAPID keys identify this server to the browsers' push services. Generate a
 * pair once with `php artisan ethr:webpush-keys` and put them in .env; never
 * rotate them casually, because every subscription made with the old public
 * key stops receiving pushes. With no keys, push is off and nothing is sent.
 */
return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_PRIVATE_KEY'),
    // A contact the push services can reach about this sender.
    'subject' => env('WEBPUSH_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'support@ethr.et')),
];
