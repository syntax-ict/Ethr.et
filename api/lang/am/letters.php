<?php

declare(strict_types=1);

return [
    // The employment letter PDF (resources/views/letters/employment.blade.php).
    'reference' => 'ቁጥር',
    'date' => 'ቀን',
    'to_whom' => 'ለሚመለከተው ሁሉ',
    'subject' => 'የሥራ ማረጋገጫ',
    'body_current' => ':name (የሠራተኛ ቁጥር :code) ከ:from ጀምሮ በ:organisation ተቀጥረው እየሠሩ መሆናቸውን እናረጋግጣለን።',
    'body_former' => ':name (የሠራተኛ ቁጥር :code) ከ:from እስከ :to በ:organisation ተቀጥረው መሥራታቸውን እናረጋግጣለን።',
    'role_department' => 'የሥራ መደባቸው በ:department ክፍል :position ነው።',
    'role' => 'የሥራ መደባቸው :position ነው።',
    'salary' => 'መሠረታዊ ወርሃዊ ደመወዛቸው :amount ብር ነው።',
    'purpose' => 'ይህ ደብዳቤ በጠያቂው ጥያቄ መሠረት ለ:purpose የተሰጠ ነው።',
    'signature' => 'የሰው ሀብት፣ :organisation',
    'issued_by' => 'የሰጡት :name',

    // Notifications and refusals.
    'requested' => ':name የሥራ ማረጋገጫ ደብዳቤ ጠይቀዋል።',
    'issued' => 'የሥራ ማረጋገጫ ደብዳቤዎ ለማውረድ ዝግጁ ነው።',
    'rejected' => 'የሥራ ማረጋገጫ ደብዳቤ ጥያቄዎ ውድቅ ተደርጓል፦ :reason',
    'mail_subject_requested' => 'የሥራ ማረጋገጫ ደብዳቤ ተጠይቋል',
    'mail_subject_decided' => 'የሥራ ማረጋገጫ ደብዳቤ ጥያቄዎ',
    'open' => 'ክፈት',
    'self_issue' => 'ስለራስዎ የሚጠየቅ ደብዳቤ ላይ መወሰን አይችሉም።',
    'not_pending' => 'ይህ ጥያቄ ቀድሞ ውሳኔ አግኝቷል።',
];
