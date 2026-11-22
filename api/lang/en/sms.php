<?php

declare(strict_types=1);

return [
    'enable_requires_verified' => 'SMS can be switched on only after the primary gateway has delivered a test message.',
    'test_message' => 'ETHR test message: SMS delivery from this gateway is working.',
    'notify' => [
        'subject' => 'SMS sign-in codes are paused',
        'intro' => 'The :gateway gateway (:slot) refused the last :failures text messages in a row, so SMS is paused.',
        'status' => 'Last answer from the gateway: HTTP :status.',
        'no_answer' => 'no answer',
        'response' => 'It said: :response',
        'retry' => 'ETHR will try SMS again after :time, or as soon as a test send succeeds in the console.',
        'meanwhile' => 'Until then, sign-in codes and invitations go by email only.',
        'open' => 'Open SMS delivery',
        'in_app' => 'SMS is paused: the gateway refused the last :failures messages.',
    ],
];
