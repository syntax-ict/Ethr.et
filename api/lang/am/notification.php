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

    // Approval routing
    'review_request' => 'ጥያቄ ይከልሱ',
    'device_offline_subject' => 'የባዮሜትሪክ መሣሪያ ከመስመር ውጭ ሆኗል',
    'device_sync_failed_subject' => 'የባዮሜትሪክ መሣሪያ ማመሳሰል አልተሳካም፦ :name',
    'device_sync_failed_body' => 'የመሣሪያ ":name" ማመሳሰል ከሁሉም ሙከራዎች በኋላ አልተሳካም፤ ከእሱ ምንም የመገኘት መዝገቦች እየተሰበሰቡ አይደሉም። መሣሪያው ያሳወቀው፦ :reason',
    'device_action' => 'የመሣሪያ ሁኔታ ይመልከቱ',

    // Billing
];
