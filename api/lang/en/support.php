<?php

declare(strict_types=1);

return [
    'send_to_forbidden' => 'Only your organisation\'s support people can send a request straight to ETHR.',
    'already_with_ethr' => 'This request is already with ETHR support.',
    'resolved_closed' => 'This request is resolved. Write a reply to open it again.',
    'message_required' => 'Write a message first.',
    'category' => [
        'sign_in' => 'Signing in',
        'attendance' => 'Attendance and punching',
        'leave' => 'Leave',
        'payroll' => 'Payroll and payslips',
        'employees' => 'Employee records',
        'devices' => 'Devices and phones',
        'billing' => 'Billing and plan',
        'other' => 'Something else',
    ],
    'notify' => [
        'open' => 'Open the request',
        'received' => 'Someone in your organisation asked for help: :category.',
        'escalated' => 'An organisation passed a help request to ETHR: :category.',
        'requester_replied' => 'There is a new reply on a help request: :category.',
        'answered' => 'Your help request has an answer: :category.',
        'passed_to_ethr' => 'Your help request was passed to ETHR support: :category.',
        'resolved' => 'Your help request was marked resolved: :category.',
    ],
];
