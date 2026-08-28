<?php

declare(strict_types=1);

return [
    // Leave notifications
    'leave_requested_subject' => 'አዲስ የፈቃድ ጥያቄ — :name',
    'leave_requested_body' => ':name የፈቃድ ጥያቄ አቅርቧል። ፈቃድዎ ያስፈልጋል።',
    'leave_approved_subject' => 'የፈቃድ ጥያቄ ተፈቅዷል',
    'leave_approved_body' => 'የፈቃድ ጥያቄዎ ተፈቅዷል።',
    'leave_rejected_subject' => 'የፈቃድ ጥያቄ ውድቅ ተደርጓል',
    'leave_rejected_body' => 'የፈቃድ ጥያቄዎ ውድቅ ተደርጓል።',

    // Payroll notifications
    'payslip_available_subject' => 'የደመወዝ ሰነድዎ ዝግጁ ነው — :period',
    'payslip_available_body' => 'ለ :period ጊዜ የደመወዝ ሰነድዎ አሁን ዝግጁ ነው። ለማየት እና ለማውረድ ይግቡ።',
    'view_payslip' => 'ደመወዝ ሰነድ ይመልከቱ',

    // Attendance — missing punch
    'missing_check_out_subject' => 'የፈቃድ ምዝገባ ጉድለት — :date',
    'missing_check_out_body' => ':name ቀኑን :date ተገኝቶ ወጣ ብሎ አልተመዘገበም። እባክዎ የታዳሚ መዝገቡን ያርሙ።',
    'missing_check_in_subject' => 'የገቢ ምዝገባ ጉድለት — :date',
    'missing_check_in_body' => ':name ለ :date ቀን ወጣ ብሎ ተመዝግቧል ነገርግን ገቢ ምዝገባ የለም። እባክዎ የታዳሚ መዝገቡን ያርሙ።',

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
];
