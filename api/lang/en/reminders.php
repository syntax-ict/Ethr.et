<?php

declare(strict_types=1);

return [
    // ExpiryReminderNotification (SendExpiryRemindersJob).
    'expiry' => [
        'summary' => ':count contract, probation period or document ends soon.|:count contracts, probation periods or documents end soon.',
        'subject' => ':count item needs attention before it ends|:count items need attention before they end',
        'intro' => 'These end soon. Renew, confirm or replace them before the date.',
        'line_contract' => 'Contract of :name ends on :date.',
        'line_probation' => 'Probation of :name ends on :date.',
        'line_document' => ':document of :name expires on :date.',
        'more' => 'And :count more.',
        'action' => 'Open employees',
    ],
];
