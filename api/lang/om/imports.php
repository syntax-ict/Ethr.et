<?php

declare(strict_types=1);

return [
    // BalanceImporter: opening leave and loan balances.
    'balances' => [
        'empty_file' => 'Faayiliin toora mataa fi yoo xiqqaate toora daataa tokko barbaada.',
        'missing_columns' => 'Kolonoota hin jirre: :columns',
        'employee_code_required' => 'employee_code barbaachisaadha.',
        'employee_not_found' => 'Hojjetaan koodii :code qabu hin jiru.',
        'leave_type_not_found' => 'Gosni boqonnaa koodii :code qabu hin jiru.',
        'invalid_year' => 'year waggaa dijiitii afurii ta\'uu qaba.',
        'invalid_days' => ':field lakkoofsa guyyootaa, yoo baay\'ate desimaala tokko qabu, ta\'uu qaba.',
        'used_exceeds' => 'used_days entitled_days fi carried_days walitti ida\'aman caala.',
        'duplicate_row' => 'Hojjetaan, gosni boqonnaa fi waggaan wal fakkaatan duraan toora :line irra jiru.',
        'invalid_amount' => ':field hanga birrii, kan akka 12500 ykn 12500.50, ta\'uu qaba.',
        'principal_positive' => 'principal_etb zeeroo caaluu qaba.',
        'remaining_exceeds' => 'remaining_etb principal_etb caala.',
        'deduction_positive' => 'Utuu hafteen jiruu monthly_deduction_etb zeeroo caaluu qaba.',
        'invalid_date' => 'start_date guyyaa akka YYYY-MM-DD barreeffame ta\'uu qaba.',
        'loan_reason' => 'Haftee jalqabaa kan dabarfame',
    ],
];
