<?php

declare(strict_types=1);

return [
    // The employment letter PDF (resources/views/letters/employment.blade.php).
    'reference' => 'Lakkoofsa wabii',
    'date' => 'Guyyaa',
    'to_whom' => 'Nama ilaallatuuf',
    'subject' => 'Mirkaneessa hojii',
    'body_current' => 'Xalayaan kun :name (lakkoofsa hojjetaa :code) guyyaa :from irraa jalqabee :organisation keessatti hojjechaa akka jiru mirkaneessa.',
    'body_former' => 'Xalayaan kun :name (lakkoofsa hojjetaa :code) guyyaa :from irraa hanga guyyaa :to :organisation keessatti hojjechaa akka ture mirkaneessa.',
    'role_department' => 'Gitni hojii isaanii muummee :department keessatti :position dha.',
    'role' => 'Gitni hojii isaanii :position dha.',
    'salary' => 'Mindaan bu\'uuraa ji\'aa isaanii birrii :amount dha.',
    'purpose' => 'Xalayaan kun gaaffii isaaniitiin, kaayyoo kanaaf kenname: :purpose.',
    'signature' => 'Qabeenya Namaa, :organisation',
    'issued_by' => 'Kan kenne: :name',

    // Notifications and refusals.
    'requested' => ':name xalayaa ragaa hojii gaafateera.',
    'issued' => 'Xalayaan ragaa hojii keessanii buusuuf qophaa\'eera.',
    'rejected' => 'Gaaffiin xalayaa ragaa hojii keessanii didameera: :reason',
    'mail_subject_requested' => 'Xalayaan ragaa hojii gaafatameera',
    'mail_subject_decided' => 'Gaaffii xalayaa ragaa hojii keessanii',
    'open' => 'Bani',
    'self_issue' => 'Xalayaa waa\'ee mataa keessanii murteessuu hin dandeessan.',
    'not_pending' => 'Gaaffiin kun duraan murtaa\'eera.',
];
