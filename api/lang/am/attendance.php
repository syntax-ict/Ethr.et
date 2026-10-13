<?php

declare(strict_types=1);

return [
    'no_employee_linked' => 'ከመለያዎ ጋር የተገናኘ የሰራተኛ መገለጫ የለም።',
    'employee_has_left' => 'ይህ ሰራተኛ ከስራ ስለለቀቀ መገኘት መመዝገብ አይችልም።',
    'no_open_check_in' => 'የሚዘጋ ክፍት የመግቢያ መዝገብ የለም።',
    'employee_not_found' => 'በተሰጠው ኮድ ሰራተኛ አልተገኘም።',
    'qr_invalid_or_expired' => 'QR ኮድ ልክ ያልሆነ ወይም ጊዜው ያለፈበት ነው።',
    'method_disabled' => 'ይህ የመገኘት ዘዴ ለድርጅትዎ አልተንቃ።',
    'geofence_location_required' => 'ጂኦፌንስ ሲነቃ GPS አካባቢ ያስፈልጋል።',
    'outside_geofence' => 'ከተፈቀደው ክልል ውጪ ነዎት። እባክዎ ወደ ቅርንጫፍ ቦታዎ ይቅረቡ።',
    'conflict_already_resolved' => 'ይህ ግጭት ቀድሞውኑ ተፈትቷል።',
    'offline_not_own' => 'ማመሳሰል የሚችሉት የራስዎን መገኘት ብቻ ነው።',
    'offline_outside_window' => 'ይህ መግቢያ ከ:days ቀናት በፊት ወይም ለወደፊት ቀን ስለተመዘገበ ሊመሳሰል አይችልም። የሰው ሀብት ክፍል እንዲያስገባው ይጠይቁ።',
    'auto_checkout' => [
        'subject' => 'ክትትልዎ በፈረቃዎ መጨረሻ ተዘግቷል',
        'message' => 'በ:date አልወጡም ነበር፤ ስለዚህ ክትትልዎ በፈረቃዎ መጨረሻ፣ :time ላይ ተዘግቷል።',
        'correct_it' => 'በሌላ ሰዓት ከወጡ ማስተካከያ ያስገቡ፤ አጽዳቂዎ ይገመግመዋል።',
        'action' => 'ማስተካከያዎችን ክፈት',
    ],
    'photo_required' => 'ለመግባት የራስዎን ፎቶ ያንሱ። ድርጅትዎ በስልክ ሲገቡ ፎቶ ይፈልጋል።',
    'auto_check_in_not_allowed' => 'ራስ-ሰር መግቢያ ለእርስዎ አልበራም።',
    'auto_check_in_outside' => 'ራስ-ሰር መግቢያ በቅርንጫፍዎ ክልል ውስጥ እንዲሆኑ ይፈልጋል።',
    'device_clock_invalid' => 'መሳሪያው የማይነበብ ወይም የወደፊት ሰዓት ልኳል። የመሳሪያውን ሰዓት ያረጋግጡ።',
    'qr_already_used' => 'ይህን የQR ኮድ አስቀድመው ተጠቅመውበታል። በስክሪኑ ላይ ያለውን የአሁኑን ኮድ ይቃኙ።',
    'qr_use_limit_reached' => 'ይህ የQR ኮድ ከፍተኛውን የአጠቃቀም ብዛት ደርሷል። በስክሪኑ ላይ ያለውን የአሁኑን ኮድ ይቃኙ።',
    'integrity_self_review' => 'በራስዎ የመገኘት መዝገብ ላይ ያለን ምልክት መገምገም አይችሉም።',
    'integrity_already_reviewed' => 'ይህ ምልክት አስቀድሞ ተገምግሟል።',
    'punch_rejected' => [
        'subject' => 'የእርስዎ የመገኘት ምዝገባ ተቀባይነት አላገኘም',
        'message' => 'በ:date ያለዎት መገኘት ተገምግሞ ተቀባይነት አላገኘም። ምክንያት፦ :reason',
        'correct_it' => 'ስህተት ከሆነ ትክክለኛውን ሰዓት የያዘ እርማት ያስገቡ፤ አጽዳቂዎ ይገመግመዋል።',
        'action' => 'እርማቶችን ክፈት',
    ],
];
