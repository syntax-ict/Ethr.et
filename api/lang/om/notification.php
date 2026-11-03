<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'Waraqaa Mindaa Ilaali',
    'view_attendance' => 'Argama Ilaali',
    'upgrade_plan' => 'Karoora Ol Guddisi',

    // Attendance corrections

    // Profile update requests
    'profile_update_requested_body' => ':name dirreewwan piroofaayilii eegaman irratti jijjiirama gaafateera.',
    'profile_update_approved_body' => 'Jijjiiramni :field irratti gaafattan raggaasifameera.',
    'profile_update_rejected_body' => 'Jijjiiramni :field irratti gaafattan didameera.',

    // Approval reminders
    'approval_reminder_subject' => 'Raggaasisoota Isin Eegaa Jiran',
    'approval_escalated_subject' => 'Raggaasisni tokko yeroon isaa darbeera',
    'approval_escalated_hint' => 'Maaloo isaan hordofaa, ykn yoo hin jiraanne akka murteessu Qabeenya Namaa (HR) gaafadhaa.',
    'approval_escalated_action' => 'Raggaasisoota bani',

    // Approval routing
    'review_request' => 'Gaaffii Ilaali',
    'device_offline_subject' => 'Meeshaan baayoometrikii tokko toora ala ta\'eera',
    'device_sync_failed_subject' => 'Walsimsiisni meeshaa baayoometrikii hin milkoofne: :name',
    'device_sync_failed_body' => 'Walsimsiisni meeshaa ":name" erga irra deebi\'amee yaalamee booda hin milkoofne, taateewwan argamaa tokkollee isa irraa sassaabamaa hin jiran. Meeshaan kana gabaase: :reason',
    'device_action' => 'Haala meeshaa ilaali',

    // Billing
    // In-app notifications, rendered in the recipient's language when sent
    // (User::preferredLocale). Each carries a `url` the notification opens.
    'inapp' => [
        'attendance_high_risk' => ':name qabxii balaa :score qabuun galmeesse. Kaffaltii mindaa dura ilaalaa.',
        'approval_reminder' => 'Raggaasisoonni :count isin eegaa jiru. Inni hundaa caalaa turee sa\'aatii :hours eegeera.',
        'approval_escalated' => ':type :name sa\'aatii :hours akka :approver murteessu eegeera.',
        'attendance_anomaly' => 'Argama :name irratti waan idilee hin taane: :type',
        'correction_approved' => 'Sirreeffamni argamaa keessan guyyaa :date raggaasifameera.',
        'correction_rejected' => 'Sirreeffamni argamaa keessan guyyaa :date didameera.',
        'correction_requested' => ':name sirreeffama argamaa gaafateera.',
        'overtime_requested' => ':name guyyaa :date sa\'aatii dabalataa :hours hojjechuu gaafateera.',
        'overtime_approved' => 'Sa\'aatiin dabalataa keessan guyyaa :date raggaasifameera.',
        'overtime_rejected' => 'Sa\'aatiin dabalataa keessan guyyaa :date hin raggaasifamne.',
        'phone_requested' => ':name hojii ala utuu jiruu bilbila galmeesseera. Erga raggaasiftanii booda galmeessuu danda\'a.',
        'phone_replacement_requested' => ':name bilbila argamaa isaa jijjiiruu gaafateera.',
        'phone_approved' => 'Bilbilli argamaa keessan raggaasifameera. Amma booda isaan galmeessaa.',
        'phone_rejected' => 'Bilbilli argamaa keessan hin raggaasifamne.',
        'phone_rejected_reason' => 'Bilbilli argamaa keessan hin raggaasifamne: :reason',
        'phone_revoked' => 'Bilbilli argamaa keessan haqameera. Bilbila amma fayyadamtan galmeessaa.',
        'phone_revoked_reason' => 'Bilbilli argamaa keessan haqameera: :reason. Bilbila amma fayyadamtan galmeessaa.',
        'device_offline' => 'Meeshaan ":name" toora ala ta\'eera.',
        'leave_approved' => 'Gaaffiin boqonnaa keessan raggaasifameera.',
        'leave_rejected' => 'Gaaffiin boqonnaa keessan didameera.',
        'leave_requested' => ':name boqonnaa gaafateera.',
        'missing_check_in' => ':name guyyaa :date seensa hin galmeessine.',
        'missing_check_out' => ':name guyyaa :date bahiinsa hin galmeessine.',
        'payroll_processed' => 'Kaffaltiin mindaa :period herregameera.',
        'payslip_available' => 'Waraqaan mindaa keessan :period qophaa\'eera.',
        'trial_expiring' => 'Yaaliin bilisaa keessan guyyaa :days keessatti xumurama. ETHR fayyadamuu itti fufuuf karoora filadhaa.|Yaaliin bilisaa keessan guyyoota :days keessatti xumurama. ETHR fayyadamuu itti fufuuf karoora filadhaa.',
    ],
    'push_unavailable' => 'Beeksisni bilbilaa ammallee sarvara kana irratti hin qindoofne.',
];
