<?php

declare(strict_types=1);

return [
    'created' => 'ሰራተኛ በተሳካ ሁኔታ ተፈጥሯል።',
    'updated' => 'የሰራተኛ መረጃ ተዘምኗል።',
    'deleted' => 'የሰራተኛ መዝገብ ተሰርዟል።',
    // English placeholder until the native-speaker review (audit N73).
    'supervisor_self' => 'ሠራተኛ የራሱ ተቆጣጣሪ መሆን አይችልም።',
    'supervisor_loop' => 'ይህ ዙር ይፈጥራል፦ ይህ ሰው የተመረጠውን ሰው በቀጥታ ወይም በበታች ደረጃ ይቆጣጠራል።',
    'import_relation_not_found' => 'ረድፍ :row፦ :field ":value" አልተገኘም። በETHR ውስጥ ያለውን ኮድ ወይም ስም ይጠቀሙ።',
    'import_relation_ambiguous' => 'ረድፍ :row፦ ":value" የሚባሉ ከአንድ በላይ :field አሉ። ኮዱን ይጠቀሙ።',
    'import_duplicate_in_file' => 'ረድፍ :row፦ :field ":value" በዚህ ፋይል ረድፍ :first ላይ ተጠቅሟል።',
    'import_already_taken' => 'ረድፍ :row፦ የሰራተኛ ኮዱ ወይም የባጅ ቁጥሩ በሌላ ሰራተኛ ተይዟል።',
    'import_needs_review' => 'ረድፍ :row፦ ይህ በETHR ውስጥ ያለ ሰራተኛ ይመስላል፣ ነገር ግን የሰራተኛ ኮዱ ወይም የመታወቂያ ቁጥሩ አይስማማም። ረድፉ አልተጨመረም። ከዚያ ሰራተኛ መዝገብ ጋር ያመሳክሩ።',
    'import_archived' => 'ረድፍ :row፦ ይህ ሰው ከETHR ተሰርዟል፣ መዝገቡ ግን ተጠብቋል። ለሁለተኛ ጊዜ አልተጨመረም።',
    'import_possible_duplicate' => 'ረድፍ :row፦ ይህ በ ETHR ውስጥ ያለው :name ሊሆን ይችላል። ረድፉ አልተጨመረም። የተለየ ሰው ከሆነ በ"ሰራተኛ ጨምር" ቅጽ ይጨምሩት።',
    'exists' => ':name አስቀድሞ እዚህ ሰራተኛ ነው። ለውጥ ለማድረግ መዝገቡን ይክፈቱ።',
    'exists_probable' => 'ይህ አስቀድሞ እዚህ ሰራተኛ የሆነው :name ሊሆን ይችላል። የተለየ ሰው ከሆነ ብቻ ይጨምሩት።',
    'exists_ambiguous' => 'ይህ አስቀድሞ እዚህ ካሉ ከአንድ በላይ ሰራተኞች ጋር ይመሳሰላል። የተለየ ሰው ከሆነ ብቻ ይጨምሩት።',
    'exists_archived' => ':name ከ ETHR ተሰርዟል፣ መዝገቡ ግን ተጠብቋል። ያንን መዝገብ ይመልሱ ወይም እንደ አዲስ ሰው ይጨምሩት።',
    'exists_former' => ':name ከዚህ በፊት እዚህ ሰርቶ ለቋል። አዲስ መዝገብ የሚያስፈልገው ከሆነ ብቻ ይጨምሩት።',
    'import_field_department_code' => 'ክፍል',
    'import_field_branch_code' => 'ቅርንጫፍ',
    'import_field_position_code' => 'የስራ መደብ',
    'import_field_employee_code' => 'የሰራተኛ ኮድ',
    'import_field_email' => 'ኢሜይል',
    'import_field_badge_number' => 'የባጅ ቁጥር',
    'import_field_external_id' => 'በሌላው ሲስተም ያለ መለያ',
    'number_format_invalid' => 'አንድ {SEQ:n} ምልክት ይጠቀሙ (n ከ1 እስከ 8)፣ ዙሪያውን ፊደላት፣ ቁጥሮች፣ - _ . ወይም / ብቻ። ለምሳሌ EMP-{SEQ:4}።',
    'cannot_change_own_pay' => 'የራስዎን ደመወዝ ወይም የባንክ መረጃ በድርጅትዎ ውስጥ ሌላ ሰው መቀየር አለበት።',
    'cannot_change_own_deductions' => 'የራስዎን ብድር ወይም ተቀናሽ በድርጅትዎ ውስጥ ሌላ ሰው መመዝገብ ወይም መቀየር አለበት።',
    'cannot_review_own_profile' => 'በራስዎ መገለጫ ላይ የቀረበ ለውጥ መገምገም አይችሉም።',
    'cannot_review_pay_field' => 'የግብር ከፋይ መለያ ቁጥርን ወይም የባንክ ሂሳብን ለውጥ ለመወሰን የክፍያ መረጃን የማስተካከል ፈቃድ ያስፈልጋል።',
    'cannot_review_filed_profile' => 'ይህን ለውጥ ያቀረቡት እርስዎ ስለሆኑ ሌላ ሰው መገምገም አለበት።',
    'invalid_transition' => 'ሁኔታን ከ:from ወደ :to መቀየር አይቻልም።',
    'bulk_update_failed' => 'ይህን ሰራተኛ ማዘመን አልተቻለም። እንደገና ይሞክሩ፣ ወይም ከመገለጫው ይቀይሩ።',
];
