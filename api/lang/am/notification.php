<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'ደመወዝ ሰነድ ይመልከቱ',

    // Attendance corrections
    'correction_submitted_subject' => 'የታዳሚ እርማት ጥያቄ — :name',
    'correction_submitted_body' => ':name ለ :date ቀን የታዳሚ እርማት ጥያቄ አቅርቧል።',
    'correction_approved_subject' => 'የታዳሚ እርማት ጥያቄ ተፈቅዷል',
    'correction_approved_body' => 'ለ :date ቀን ያቀረቡት የታዳሚ እርማት ጥያቄ ተፈቅዷል።',
    'correction_rejected_subject' => 'የታዳሚ እርማት ጥያቄ ውድቅ ተደርጓል',
    'correction_rejected_body' => 'ለ :date ቀን ያቀረቡት የታዳሚ እርማት ጥያቄ ውድቅ ተደርጓል።',

    // Profile update requests
    'profile_update_requested_subject' => 'የመገለጫ ለውጥ ግምገማ ይጠብቃል',
    'profile_update_requested_body' => ':name በተጠበቁ የመገለጫ መስኮች ላይ ለውጥ ጠይቀዋል።',
    'profile_update_approved_body' => 'ለ :field ያቀረቡት የለውጥ ጥያቄ ተፈቅዷል።',
    'profile_update_rejected_body' => 'ለ :field ያቀረቡት የለውጥ ጥያቄ ውድቅ ተደርጓል።',

    // Approval reminders
    'approval_reminder_subject' => 'እርስዎን የሚጠብቁ ማጽደቆች',
    'approval_reminder_body' => 'ከ :hours ሰዓታት በላይ የቆዩ :count ጥያቄ(ዎች) አለዎት።',

    // Approval routing
    'review_request' => 'ጥያቄ ይከልሱ',
    'device_offline_subject' => 'የባዮሜትሪክ መሣሪያ ከመስመር ውጭ ሆኗል',
    'device_sync_failed_subject' => 'የባዮሜትሪክ መሣሪያ ማመሳሰል አልተሳካም፦ :name',
    'device_sync_failed_body' => 'የመሣሪያ ":name" ማመሳሰል ከሁሉም ሙከራዎች በኋላ አልተሳካም፤ ከእሱ ምንም የመገኘት መዝገቦች እየተሰበሰቡ አይደሉም። መሣሪያው ያሳወቀው፦ :reason',
    'device_action' => 'የመሣሪያ ሁኔታ ይመልከቱ',

    // Billing
    'trial_expiring_subject' => 'የETHR የሙከራ ጊዜዎ በቅርቡ ያበቃል',
];
