<?php

declare(strict_types=1);

return [
    // BalanceImporter: opening leave and loan balances.
    'balances' => [
        'empty_file' => 'ፋይሉ የርዕስ ረድፍ እና ቢያንስ አንድ የውሂብ ረድፍ ያስፈልገዋል።',
        'missing_columns' => 'የጎደሉ ዓምዶች፦ :columns',
        'employee_code_required' => 'employee_code ያስፈልጋል።',
        'employee_not_found' => ':code ኮድ ያለው ሠራተኛ የለም።',
        'leave_type_not_found' => ':code ኮድ ያለው የፈቃድ ዓይነት የለም።',
        'invalid_year' => 'year ባለ አራት አሃዝ ዓመት መሆን አለበት።',
        'invalid_days' => ':field ቢበዛ አንድ አስርዮሽ ያለው የቀናት ቁጥር መሆን አለበት።',
        'used_exceeds' => 'used_days ከentitled_days እና carried_days ድምር ይበልጣል።',
        'duplicate_row' => 'ተመሳሳይ ሠራተኛ፣ የፈቃድ ዓይነት እና ዓመት በረድፍ :line ላይ አለ።',
        'invalid_amount' => ':field እንደ 12500 ወይም 12500.50 ያለ የብር መጠን መሆን አለበት።',
        'principal_positive' => 'principal_etb ከዜሮ መብለጥ አለበት።',
        'remaining_exceeds' => 'remaining_etb ከprincipal_etb ይበልጣል።',
        'deduction_positive' => 'ቀሪ ሂሳብ እስካለ ድረስ monthly_deduction_etb ከዜሮ መብለጥ አለበት።',
        'invalid_date' => 'start_date በYYYY-MM-DD የተጻፈ ቀን መሆን አለበት።',
        'loan_reason' => 'ከቀድሞው ሥርዓት የተዛወረ የመክፈቻ ቀሪ ሂሳብ',
    ],
];
