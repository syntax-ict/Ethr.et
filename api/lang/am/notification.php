<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'ደመወዝ ሰነድ ይመልከቱ',
    'view_attendance' => 'መገኘትን ይመልከቱ',
    'upgrade_plan' => 'ዕቅድ ያሻሽሉ',

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
    'device_silent_adms_subject' => 'የባዮሜትሪክ መሣሪያ አልተገናኘም፦ :name',
    'device_silent_push_subject' => 'ዛሬ ከባዮሜትሪክ መሣሪያ ምንም ምዝገባ አልመጣም፦ :name',
    'device_sync_failed_subject' => 'የባዮሜትሪክ መሣሪያ ማመሳሰል አልተሳካም፦ :name',
    'device_sync_failed_body' => 'የመሣሪያ ":name" ማመሳሰል ከሁሉም ሙከራዎች በኋላ አልተሳካም፤ ከእሱ ምንም የመገኘት መዝገቦች እየተሰበሰቡ አይደሉም። መሣሪያው ያሳወቀው፦ :reason',
    'device_action' => 'የመሣሪያ ሁኔታ ይመልከቱ',

    // Billing
    // In-app notifications, rendered in the recipient's language when sent
    // (User::preferredLocale). Each carries a `url` the notification opens.
    'inapp' => [
        'attendance_high_risk' => ':name በ:score የአደጋ ነጥብ ተመዝግቧል። ከደመወዝ በፊት ይገምግሙት።',
        'approval_reminder' => ':count የሚጠብቁ ማጽደቂያዎች አሉዎት። በጣም የቆየው :hours ሰዓት ጠብቋል።',
        'approval_escalated' => 'የ:name :type :approver እንዲወስኑት :hours ሰዓት ጠብቋል።',
        'attendance_anomaly' => 'የ:name የመገኘት ያልተለመደ ሁኔታ፦ :type',
        'correction_approved' => 'የ:date የመገኘት ማስተካከያ ጥያቄዎ ጸድቋል።',
        'correction_rejected' => 'የ:date የመገኘት ማስተካከያ ጥያቄዎ ውድቅ ተደርጓል።',
        'correction_requested' => ':name የመገኘት ማስተካከያ ጠይቀዋል።',
        'overtime_requested' => ':name በ:date :hours ሰዓት የትርፍ ሰዓት ለመሥራት ጠይቀዋል።',
        'overtime_approved' => 'ለ:date የጠየቁት የትርፍ ሰዓት ጸድቋል።',
        'overtime_rejected' => 'ለ:date የጠየቁት የትርፍ ሰዓት አልጸደቀም።',
        'phone_requested' => ':name ከሥራ ቦታ ውጭ ሆነው ስልክ አስመዝግበዋል። ሲያጸድቁት መመዝገብ ይጀምራል።',
        'phone_replacement_requested' => ':name የሚመዘገቡበትን ስልክ ለመቀየር ጠይቀዋል።',
        'phone_approved' => 'የሚመዘገቡበት ስልክ ጸድቋል። ከአሁን ጀምሮ በእሱ ይመዝገቡ።',
        'phone_rejected' => 'የሚመዘገቡበት ስልክ አልጸደቀም።',
        'phone_rejected_reason' => 'የሚመዘገቡበት ስልክ አልጸደቀም፦ :reason',
        'phone_revoked' => 'የሚመዘገቡበት ስልክ ተሰርዟል። አሁን የሚጠቀሙበትን ስልክ ያስመዝግቡ።',
        'phone_revoked_reason' => 'የሚመዘገቡበት ስልክ ተሰርዟል፦ :reason። አሁን የሚጠቀሙበትን ስልክ ያስመዝግቡ።',
        'device_offline' => '":name" መሣሪያ ከመስመር ውጭ ሆኗል።',
        'device_silent_adms' => '":name" መሣሪያ አልተገናኘም።',
        'device_silent_push' => 'ዛሬ ከ":name" መሣሪያ ምንም ምዝገባ አልመጣም።',
        'title_access_held' => 'የስራ መደባቸውን የሚከተሉ :count መለያዎች ሚናቸውን እየጠበቁ ነው።',
        'leave_approved' => 'የፈቃድ ጥያቄዎ ጸድቋል።',
        'leave_changed' => 'የጸደቀ ፈቃድዎ በሰው ሀብት ተቀይሯል።',
        'leave_rejected' => 'የፈቃድ ጥያቄዎ ውድቅ ተደርጓል።',
        'leave_requested' => ':name ፈቃድ ጠይቀዋል።',
        'missing_check_in' => ':name በ:date አልገቡም።',
        'missing_check_out' => ':name በ:date አልወጡም።',
        'payroll_processed' => 'የ:period ደመወዝ ተሰልቷል።',
        'payslip_available' => 'የ:period የደመወዝ ወረቀትዎ ዝግጁ ነው።',
        'trial_expiring' => 'የሙከራ ጊዜዎ በ:days ቀን ውስጥ ያበቃል። ETHRን መጠቀም ለመቀጠል ዕቅድ ይምረጡ።',
    ],
    'push_unavailable' => 'የስልክ ማሳወቂያዎች በዚህ አገልጋይ ላይ ገና አልተዘጋጁም።',
    'approval_reminder_line' => ':count የሚጠብቁዎት የማጽደቅ ጥያቄዎች አሉ።',
    'approval_reminder_oldest' => 'በጣም የቆየው ለ:hours ሰዓት ሲጠብቅ ቆይቷል።',
    'approval_reminder_action' => 'ማጽደቂያዎችን ይመልከቱ',
    'device_offline_line' => 'በ:location ያለው ":name" መሣሪያ ከመስመር ውጭ ሆኗል።',
    'device_offline_action' => 'የመሣሪያውን ሁኔታ ይመልከቱ',
    'device_silent_adms_line' => 'በ:location ያለው ":name" መሣሪያ በሥራ ሰዓት ለተወሰነ ጊዜ ከETHR ጋር አልተገናኘም።',
    'device_silent_push_line' => 'በ:location ያለው ":name" መሣሪያ ዛሬ ምንም ምዝገባ አልላከም፤ ሆኖም በዚያ የሚሠሩ ሰዎች የባጅ ቁጥር አላቸው።',
    'device_silent_hint' => 'ዛሬ መሥሪያ ቤቱ ዝግ ከሆነ ምንም ማድረግ አያስፈልግም። ካልሆነ መሣሪያው መብራቱንና ኢንተርኔት ማግኘቱን ያረጋግጡ።',
    'device_silent_action' => 'መሣሪያውን ይመልከቱ',
    'title_access_held_subject' => 'የስራ መደባቸውን የሚከተሉ :count መለያዎች ሚናቸውን እየጠበቁ ነው',
    'title_access_held_line' => 'የስራ መደባቸውን የሚከተሉ :count መለያዎች አሉ፤ ሆኖም ሚናቸው መደቡ የሚሰጠው ገና አይደለም።',
    'title_access_held_hint' => 'ተጠቃሚዎችና መዳረሻን ይክፈቱ፣ እያንዳንዱ መደብ የሚሰጠውን ሚና ይመልከቱ፣ የሚስማሙበትን ላይ ተግብር ይጫኑ።',
    'title_access_held_action' => 'መለያዎቹን ይመልከቱ',
    'unknown_location' => 'ስም ያልተሰጠው ቦታ',
];
