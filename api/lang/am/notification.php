<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'ደመወዝ ሰነድ ይመልከቱ',

    // Attendance corrections

    // Profile update requests
    'profile_update_requested_body' => ':name በተጠበቁ የመገለጫ መስኮች ላይ ለውጥ ጠይቀዋል።',
    'profile_update_approved_body' => 'ለ :field ያቀረቡት የለውጥ ጥያቄ ተፈቅዷል።',
    'profile_update_rejected_body' => 'ለ :field ያቀረቡት የለውጥ ጥያቄ ውድቅ ተደርጓል።',

    // Approval reminders
    'approval_reminder_subject' => 'እርስዎን የሚጠብቁ ማጽደቆች',
    'approval_escalated_subject' => 'የዘገየ ማጽደቂያ አለ',
    'approval_escalated_hint' => 'እባክዎ ይከታተሉ፤ ከሌሉ የሰው ሀብት እንዲወስነው ይጠይቁ።',
    'approval_escalated_action' => 'ማጽደቂያዎችን ክፈት',

    // Approval routing
    'review_request' => 'ጥያቄ ይከልሱ',
    'device_offline_subject' => 'የባዮሜትሪክ መሣሪያ ከመስመር ውጭ ሆኗል',
    'device_sync_failed_subject' => 'የባዮሜትሪክ መሣሪያ ማመሳሰል አልተሳካም፦ :name',
    'device_sync_failed_body' => 'የመሣሪያ ":name" ማመሳሰል ከሁሉም ሙከራዎች በኋላ አልተሳካም፤ ከእሱ ምንም የመገኘት መዝገቦች እየተሰበሰቡ አይደሉም። መሣሪያው ያሳወቀው፦ :reason',
    'device_action' => 'የመሣሪያ ሁኔታ ይመልከቱ',

    // Billing
    // In-app notifications, rendered in the recipient's language when sent
    // (User::preferredLocale). Each carries a `url` the notification opens.
    'inapp' => [
        'approval_reminder' => ':count የሚጠብቁ ማጽደቂያዎች አሉዎት። በጣም የቆየው :hours ሰዓት ጠብቋል።',
        'approval_escalated' => 'የ:name :type :approver እንዲወስኑት :hours ሰዓት ጠብቋል።',
        'attendance_anomaly' => 'የ:name የመገኘት ያልተለመደ ሁኔታ፦ :type',
        'correction_approved' => 'የ:date የመገኘት ማስተካከያ ጥያቄዎ ጸድቋል።',
        'correction_rejected' => 'የ:date የመገኘት ማስተካከያ ጥያቄዎ ውድቅ ተደርጓል።',
        'correction_requested' => ':name የመገኘት ማስተካከያ ጠይቀዋል።',
        'overtime_requested' => ':name በ:date :hours ሰዓት የትርፍ ሰዓት ለመሥራት ጠይቀዋል።',
        'overtime_approved' => 'ለ:date የጠየቁት የትርፍ ሰዓት ጸድቋል።',
        'overtime_rejected' => 'ለ:date የጠየቁት የትርፍ ሰዓት አልጸደቀም።',
        'device_offline' => '":name" መሣሪያ ከመስመር ውጭ ሆኗል።',
        'leave_approved' => 'የፈቃድ ጥያቄዎ ጸድቋል።',
        'leave_rejected' => 'የፈቃድ ጥያቄዎ ውድቅ ተደርጓል።',
        'leave_requested' => ':name ፈቃድ ጠይቀዋል።',
        'missing_check_in' => ':name በ:date አልገቡም።',
        'missing_check_out' => ':name በ:date አልወጡም።',
        'payroll_processed' => 'የ:period ደመወዝ ተሰልቷል።',
        'payslip_available' => 'የ:period የደመወዝ ወረቀትዎ ዝግጁ ነው።',
        'trial_expiring' => 'የሙከራ ጊዜዎ በ:days ቀን ውስጥ ያበቃል። ETHRን መጠቀም ለመቀጠል ዕቅድ ይምረጡ።',
    ],
];
