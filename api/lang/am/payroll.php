<?php

declare(strict_types=1);

return [
    'not_completed' => 'የደመወዝ አሂድ ገና አልተጠናቀቀም።',
    'cannot_void' => 'የተጠናቀቀ ወይም የጸደቀ የደመወዝ አሂድ ብቻ ሊሰረዝ ይችላል።',
    'cannot_reprocess' => 'የተሰረዘ የደመወዝ አሂድ ብቻ እንደገና ሊሰራ ይችላል።',
    'fiscal_year_start_month' => 'የበጀት ዓመት መጀመሪያ ወር',
    'tax_bracket_must_start_at_zero' => 'የመጀመሪያው የግብር ደረጃ ከ0 መጀመር አለበት።',
    'tax_bracket_only_last_open_ended' => 'የመጨረሻው የግብር ደረጃ ብቻ ጣሪያ የሌለው ሊሆን ይችላል።',
    'tax_bracket_max_after_min' => 'የግብር ደረጃ ጣሪያ ከዝቅተኛው መጠን መብለጥ አለበት።',
    'tax_bracket_not_contiguous' => 'የግብር ደረጃዎች ተከታታይ መሆን አለባቸው — እያንዳንዱ ደረጃ ካለፈው ደረጃ መጨረሻ አንድ ሳንቲም በኋላ መጀመር አለበት።',
    'loan_not_active' => 'ንቁ የሆነ ብድር ብቻ ሊስተካከል ይችላል።',
    'cost_sharing_already_active' => 'ይህ ሠራተኛ አስቀድሞ ንቁ የወጪ መጋራት ግዴታ አለው።',
    'cost_sharing_invalid_transition' => 'ይህ የወጪ መጋራት ሁኔታ ለውጥ አይፈቀድም።',
    'run_failed_subject' => 'የደመወዝ ሂደት አልተሳካም፦ :period',
    'run_failed_body' => 'የ:period ደመወዝ ሂደት ሊጠናቀቅ ስላልቻለ እንዳልተሳካ ተመዝግቧል። ምንም የደመወዝ ደረሰኝ አልተሰጠም። ምክንያት፦ :reason',
    'run_failed_next_step' => 'ይህ ሂደት በራስ-ሰር እንደገና አይሞከርም። ምክንያቱን ከገመገሙ በኋላ ሂደቱን እንደገና ያስገቡ።',
];
