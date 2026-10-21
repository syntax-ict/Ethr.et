<?php

declare(strict_types=1);

return [
    // ExpiryReminderNotification (SendExpiryRemindersJob).
    'expiry' => [
        'summary' => ':count ውሎች፣ የሙከራ ጊዜያት ወይም ሰነዶች በቅርቡ ያበቃሉ።',
        'subject' => ':count ነገሮች ከማብቃታቸው በፊት ትኩረት ይፈልጋሉ',
        'intro' => 'እነዚህ በቅርቡ ያበቃሉ። ከቀኑ በፊት ያድሱ፣ ያረጋግጡ ወይም ይተኩ።',
        'line_contract' => 'የ:name ውል በ:date ያበቃል።',
        'line_probation' => 'የ:name የሙከራ ጊዜ በ:date ያበቃል።',
        'line_document' => 'የ:name :document በ:date ጊዜው ያልፋል።',
        'more' => 'እና ሌሎች :count።',
        'action' => 'ሠራተኞችን ይክፈቱ',
    ],
];
