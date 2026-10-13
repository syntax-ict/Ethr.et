<?php

declare(strict_types=1);

return [
    'pulse_already_answered' => 'You have already answered this week. Thank you - the next pulse opens on Monday.',
    'message_required' => 'Write a message first.',
    'recognition_self' => 'Recognise a colleague - you cannot recognise yourself.',
    'category' => [
        'safety' => 'Health and safety',
        'harassment' => 'Harassment or bullying',
        'discrimination' => 'Discrimination',
        'manager' => 'My manager',
        'workload' => 'Workload or hours',
        'pay' => 'Pay or benefits',
        'facilities' => 'Facilities or equipment',
        'ethics' => 'Ethics, fraud or misconduct',
        'wellbeing' => 'Wellbeing',
        'other' => 'Something else',
    ],
    'status' => [
        'new' => 'New',
        'in_review' => 'In review',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],
    'value' => [
        'teamwork' => 'Teamwork',
        'customer_care' => 'Customer care',
        'integrity' => 'Integrity',
        'initiative' => 'Initiative',
        'excellence' => 'Excellence',
        'helping_hand' => 'A helping hand',
    ],
    'notify' => [
        'raised_subject' => 'A concern was raised',
        'raised' => 'A new concern was raised: :category. Open it to respond.',
        'updated_subject' => 'Update on a concern',
        'hr_replied' => 'HR replied to your concern ":subject".',
        'status_changed' => 'Your concern ":subject" is now: :status.',
        'reporter_replied' => 'The reporter replied on the concern ":subject".',
        'recognised_subject' => 'A colleague recognised you',
        'recognised' => ':name recognised you for :value.',
        'open' => 'Open',
    ],
];
