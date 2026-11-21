<?php

declare(strict_types=1);

return [
    'enable_requires_verified' => 'SMS ሊበራ የሚችለው ዋናው መተላለፊያ የሙከራ መልዕክት ካደረሰ በኋላ ብቻ ነው።',
    'test_message' => 'የETHR የሙከራ መልዕክት፦ ከዚህ መተላለፊያ የሚላክ SMS እየሠራ ነው።',
    'notify' => [
        'subject' => 'የSMS መግቢያ ኮዶች ቆመዋል',
        'intro' => ':gateway መተላለፊያ (:slot) የመጨረሻዎቹን :failures የጽሑፍ መልዕክቶች በተከታታይ ስላልተቀበለ SMS ቆሟል።',
        'status' => 'መተላለፊያው የሰጠው የመጨረሻ መልስ፦ HTTP :status።',
        'no_answer' => 'መልስ የለም',
        'response' => 'የተነገረው፦ :response',
        'retry' => 'ETHR ከ:time በኋላ፣ ወይም በኮንሶሉ ውስጥ የሙከራ መልዕክት እንደተሳካ SMS ን እንደገና ይሞክራል።',
        'meanwhile' => 'እስከዚያ ድረስ የመግቢያ ኮዶችና ግብዣዎች በኢሜይል ብቻ ይላካሉ።',
        'open' => 'የSMS መላኪያን ክፈት',
        'in_app' => 'SMS ቆሟል፦ መተላለፊያው የመጨረሻዎቹን :failures መልዕክቶች አልተቀበለም።',
    ],
];
