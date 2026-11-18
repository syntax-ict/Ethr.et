<?php

declare(strict_types=1);

return [
    // The payslip PDF (resources/views/payslip.blade.php), in the reader's language.
    'title' => 'የ:period የደመወዝ ወረቀት',
    'voided' => 'ተሰርዟል',
    'employee' => 'ሠራተኛ',
    'code' => 'ኮድ',
    'department' => 'ክፍል',
    'position' => 'የሥራ መደብ',
    'earnings' => 'ገቢዎች',
    'basic_salary' => 'መሠረታዊ ደመወዝ',
    'overtime' => 'የትርፍ ሰዓት ክፍያ',
    'allowance' => 'አበል',
    'gross' => 'ጠቅላላ ደመወዝ',
    'deductions' => 'ተቀናሾች',
    'income_tax' => 'የገቢ ግብር',
    'employee_pension' => 'የሠራተኛ ጡረታ',
    'other_deductions' => 'ሌሎች ተቀናሾች',
    'net' => 'የተጣራ ክፍያ',
    'employer_pension' => 'የአሠሪ ጡረታ መዋጮ',
    'currency' => 'ብር',
    'generated' => ':date የተዘጋጀ። ይህ በኮምፒውተር የተዘጋጀ ሰነድ ነው።',
    'powered_by' => ':organisation፣ በETHR',
    'employer_tin' => 'የአሠሪ TIN',
    'employee_tin' => 'የሠራተኛ TIN',
    'pension_number' => 'የጡረታ ቁጥር',
    'bank' => 'ባንክ',
    'account' => 'የሒሳብ ቁጥር',
    'pay_date' => 'የክፍያ ቀን',
    'allowance_types' => [
        'fixed' => 'ቋሚ አበል',
        'percentage' => 'መቶኛ አበል',
    ],
    'deduction_types' => [
        'loan' => 'የብድር ክፍያ',
        'cost_sharing' => 'የወጪ መጋራት',
    ],
];
