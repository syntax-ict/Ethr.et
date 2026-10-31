<?php

declare(strict_types=1);

return [
    'created' => 'ሰራተኛ በተሳካ ሁኔታ ተፈጥሯል።',
    'updated' => 'የሰራተኛ መረጃ ተዘምኗል።',
    'deleted' => 'የሰራተኛ መዝገብ ተሰርዟል።',
    // English placeholder until the native-speaker review (audit N73).
    'supervisor_self' => 'ሠራተኛ የራሱ ተቆጣጣሪ መሆን አይችልም።',
    'import_relation_not_found' => 'ረድፍ :row፦ :field ":value" አልተገኘም። በETHR ውስጥ ያለውን ኮድ ወይም ስም ይጠቀሙ።',
    'import_relation_ambiguous' => 'ረድፍ :row፦ ":value" የሚባሉ ከአንድ በላይ :field አሉ። ኮዱን ይጠቀሙ።',
    'import_duplicate_in_file' => 'ረድፍ :row፦ :field ":value" በዚህ ፋይል ረድፍ :first ላይ ተጠቅሟል።',
    'import_already_taken' => 'ረድፍ :row፦ የሰራተኛ ኮዱ ወይም የባጅ ቁጥሩ በሌላ ሰራተኛ ተይዟል።',
    'import_field_department_code' => 'ክፍል',
    'import_field_branch_code' => 'ቅርንጫፍ',
    'import_field_position_code' => 'የስራ መደብ',
    'import_field_employee_code' => 'የሰራተኛ ኮድ',
    'import_field_email' => 'ኢሜይል',
    'import_field_badge_number' => 'የባጅ ቁጥር',
    'number_format_invalid' => 'አንድ {SEQ:n} ምልክት ይጠቀሙ (n ከ1 እስከ 8)፣ ዙሪያውን ፊደላት፣ ቁጥሮች፣ - _ . ወይም / ብቻ። ለምሳሌ EMP-{SEQ:4}።',
    'cannot_change_own_pay' => 'የራስዎን ደመወዝ ወይም የባንክ መረጃ በድርጅትዎ ውስጥ ሌላ ሰው መቀየር አለበት።',
    'cannot_review_own_profile' => 'በራስዎ መገለጫ ላይ የቀረበ ለውጥ መገምገም አይችሉም።',
    'cannot_review_filed_profile' => 'ይህን ለውጥ ያቀረቡት እርስዎ ስለሆኑ ሌላ ሰው መገምገም አለበት።',
    'invalid_transition' => 'ሁኔታን ከ:from ወደ :to መቀየር አይቻልም።',
    'bulk_update_failed' => 'ይህን ሰራተኛ ማዘመን አልተቻለም። እንደገና ይሞክሩ፣ ወይም ከመገለጫው ይቀይሩ።',
];
