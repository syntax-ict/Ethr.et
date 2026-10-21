<?php

declare(strict_types=1);

return [
    // The employment letter PDF (resources/views/letters/employment.blade.php).
    'reference' => 'Reference',
    'date' => 'Date',
    'to_whom' => 'To whom it may concern',
    'subject' => 'Confirmation of employment',
    'body_current' => 'This is to confirm that :name (employee number :code) has been employed by :organisation since :from.',
    'body_former' => 'This is to confirm that :name (employee number :code) was employed by :organisation from :from to :to.',
    'role_department' => 'Their position is :position in the :department department.',
    'role' => 'Their position is :position.',
    'salary' => 'Their basic monthly salary is :amount birr.',
    'purpose' => 'This letter is issued at their request, for: :purpose.',
    'signature' => 'Human Resources, :organisation',
    'issued_by' => 'Issued by :name',

    // Notifications and refusals.
    'requested' => ':name asked for an employment letter.',
    'issued' => 'Your employment letter is ready to download.',
    'rejected' => 'Your employment letter request was declined: :reason',
    'mail_subject_requested' => 'Employment letter requested',
    'mail_subject_decided' => 'Your employment letter request',
    'open' => 'Open',
    'self_issue' => 'You cannot decide a letter about yourself.',
    'not_pending' => 'This request has already been decided.',
];
