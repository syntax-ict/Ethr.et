<?php

declare(strict_types=1);

/*
 * የሕዝብ የተቋም ገጽ ጽሑፎች — Amharic strings for the public tenant landing page.
 *
 * Key-for-key identical with lang/en/public.php, asserted by
 * tests/Feature/Public/PublicLangParityTest.php.
 *
 * Amharic runs longer than English for the same meaning, so the stylesheet sets
 * line-height 1.7 on Ethiopic text (the design system calls for 1.6–1.8 against
 * 1.5 for Latin) and the layout wraps rather than truncating.
 */

return [
    'skip_to_content' => 'ወደ ይዘቱ ዝለል',
    'nav_label' => 'ድርጅት',
    'employee_sign_in' => 'የሠራተኛ መግቢያ',
    'logo_alt' => 'የ:organization አርማ',

    'about_heading' => 'ስለ :organization',
    'contact_heading' => 'አድራሻ',
    'phone' => 'ስልክ',
    'email' => 'ኢሜይል',
    'address' => 'አድራሻ',
    'website' => 'ድረ ገጽ',
    'powered_by' => 'የቀረበው በ',

    'social' => [
        'facebook' => 'ፌስቡክ',
        'instagram' => 'ኢንስታግራም',
        'linkedin' => 'ሊንክድኢን',
        'x' => 'ኤክስ',
        'youtube' => 'ዩቲዩብ',
        'telegram' => 'ቴሌግራም',
        'tiktok' => 'ቲክቶክ',
    ],

    'unavailable' => [
        'title' => 'ይህ ገጽ አይገኝም',
        'body' => 'በዚህ አድራሻ ላይ የሕዝብ ገጽ የለም። ድርጅትዎን እየፈለጉ ከሆነ አድራሻውን ያረጋግጡ ወይም ትክክለኛውን አገናኝ ከሰው ሀብት ክፍልዎ ይጠይቁ።',
        'rate_limited_title' => 'በጣም ብዙ ጥያቄዎች',
        'rate_limited_body' => 'ይህ ገጽ ከእርስዎ ግንኙነት በጣም ብዙ ጊዜ ተጠይቋል። እባክዎ ትንሽ ቆይተው እንደገና ይሞክሩ።',
        'error_title' => 'የሆነ ችግር ተፈጥሯል',
        'error_body' => 'ይህን ገጽ መጫን አልቻልንም። እባክዎ ከጥቂት ጊዜ በኋላ እንደገና ይሞክሩ።',
        'cta' => 'ወደ ETHR ይሂዱ',
    ],
];
