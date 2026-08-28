<?php

declare(strict_types=1);

return [
    'failed' => 'እነዚህ ማረጋገጫዎች ከመዝገባችን ጋር አይዛመዱም።',
    'throttle' => 'ብዙ የመግቢያ ሙከራዎች። እባክዎ ከ :seconds ሰከንዶች በኋላ ይሞክሩ።',
    'login_success' => 'በተሳካ ሁኔታ ገብተዋል።',
    'logout_success' => 'በተሳካ ሁኔታ ወጥተዋል።',
    'mfa_required' => 'ባለ ሁለት ደረጃ ማረጋገጫ ያስፈልጋል።',
    'mfa_invalid' => 'ልክ ያልሆነ የማረጋገጫ ኮድ።',
    'mfa_enabled' => 'ባለ ሁለት ደረጃ ማረጋገጫ ነቅቷል።',
    'mfa_disabled' => 'ባለ ሁለት ደረጃ ማረጋገጫ ተሰናክሏል።',
    'token_refreshed' => 'ቶከን በተሳካ ሁኔታ ታድሷል።',
    'unauthorized' => 'ይህን ተግባር ለማከናወን ስልጣን የለዎትም።',
    'tenant_inactive' => 'የድርጅትዎ መለያ ንቁ አይደለም።',
    'account_suspended' => 'መለያዎ ታግዷል።',
    'impersonation_restricted' => 'ተከራይን በመወከል ላይ ሳሉ ይህን ተግባር ማከናወን አይቻልም።',
    'not_impersonating' => 'ንቁ የመወከል ክፍለ ጊዜ የለም።',
    'impersonation_ended' => 'የመወከል ክፍለ ጊዜ ተጠናቅቋል።',
    'sso_not_configured' => 'ለዚህ ድርጅት ነጠላ መግቢያ አልተዋቀረም።',
    'sso_failed' => 'የSSO ማረጋገጫ አልተሳካም። እንደገና ይሞክሩ ወይም አስተዳዳሪዎን ያግኙ።',
    'sso_no_account' => 'ለዚህ SSO ማንነት መለያ አልተገኘም። አስተዳዳሪዎን ያግኙ።',

    // Account lockout alerting
    'lockout_alert_title' => 'ተደጋጋሚ ያልተሳኩ የመግቢያ ሙከራዎች በኋላ መለያ ተቆልፏል',
    'lockout_alert_body' => 'መለያ ":identifier" ከIP :ip ተደጋጋሚ ያልተሳኩ የመግቢያ ሙከራዎች በኋላ ለ :minutes ደቂቃዎች ተቆልፏል።',

    // Session management
    'session_revoked' => 'ክፍለ ጊዜ ተሰርዟል።',
    'sessions_revoked' => 'ሁሉም ሌሎች ክፍለ ጊዜዎች ወጥተዋል።',
    'session_not_found' => 'ያ ክፍለ ጊዜ ከእንግዲህ የለም።',
    'session_current' => 'ይህ መሣሪያ',

    // Password policy
    'password_too_short' => 'የይለፍ ቃሉ ቢያንስ :min ቁምፊዎች መሆን አለበት።',
    'password_needs_uppercase' => 'የይለፍ ቃሉ ቢያንስ አንድ አቢይ ሆሄ መያዝ አለበት።',
    'password_needs_lowercase' => 'የይለፍ ቃሉ ቢያንስ አንድ ንዑስ ሆሄ መያዝ አለበት።',
    'password_needs_number' => 'የይለፍ ቃሉ ቢያንስ አንድ ቁጥር መያዝ አለበት።',
    'password_needs_symbol' => 'የይለፍ ቃሉ ቢያንስ አንድ ምልክት መያዝ አለበት።',
    'password_expired' => 'የይለፍ ቃልዎ ጊዜው አልፎበታል። እባክዎ አዲስ ያዘጋጁ።',

    // OTP
    'otp_sent' => 'መለያው ካለ የማረጋገጫ ኮድ ተልኳል።',
    'otp_invalid' => 'ያ የማረጋገጫ ኮድ ልክ ያልሆነ ወይም ጊዜው ያለፈበት ነው።',
    'otp_unavailable' => 'የማረጋገጫ ኮዶች ሊላኩ አይችሉም — የSMS መተላለፊያ አልተዋቀረም።',
    'otp_message' => 'የእርስዎ ETHR ማረጋገጫ ኮድ :code ነው። በ :minutes ደቂቃዎች ውስጥ ጊዜው ያበቃል።',

    // Trusted devices
    'device_trusted' => 'ይህ መሣሪያ ለ :days ቀናት ይታወሳል።',

    'mfa_incomplete' => 'ይህን መለያ ከመጠቀምዎ በፊት ባለ ሁለት ደረጃ ማረጋገጫውን ያጠናቅቁ።',

    // Platform console
    'platform_mfa_required' => 'በመድረክ ኮንሶል ውስጥ ለውጥ ከማድረግዎ በፊት በመለያዎ ላይ ባለ ሁለት ደረጃ ማረጋገጫ (MFA) መንቃት አለበት። በመገለጫ > ደህንነት ስር ያዋቅሩት።',
];
