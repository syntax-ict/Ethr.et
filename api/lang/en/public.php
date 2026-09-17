<?php

declare(strict_types=1);

/*
 * Strings for the public tenant landing page.
 *
 * Kept key-for-key identical with lang/am/public.php. The frontend i18n gate
 * (scripts/i18n-check.js) only scans src/, so nothing automated covers these
 * two files except tests/Feature/Public/PublicLangParityTest.php — which is why
 * that test exists rather than being assumed unnecessary.
 */

return [
    'skip_to_content' => 'Skip to content',
    'nav_label' => 'Organization',
    'employee_sign_in' => 'Employee sign in',
    'logo_alt' => ':organization logo',

    'about_heading' => 'About :organization',
    'contact_heading' => 'Contact',
    'phone' => 'Phone',
    'email' => 'Email',
    'address' => 'Address',
    'website' => 'Website',
    'powered_by' => 'Powered by',

    'social' => [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'x' => 'X',
        'youtube' => 'YouTube',
        'telegram' => 'Telegram',
        'tiktok' => 'TikTok',
    ],

    'unavailable' => [
        'title' => 'This page is not available',
        'body' => 'There is no public page at this address. If you were looking for your organization, check the web address or ask your HR team for the right link.',
        'rate_limited_title' => 'Too many requests',
        'rate_limited_body' => 'This page has been requested too many times from your connection. Please wait a moment and try again.',
        'error_title' => 'Something went wrong',
        'error_body' => 'We could not load this page. Please try again shortly.',
        'cta' => 'Go to ETHR',
    ],
];
