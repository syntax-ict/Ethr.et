<?php

declare(strict_types=1);

return [
    'send_to_forbidden' => 'ጥያቄን በቀጥታ ወደ ETHR መላክ የሚችሉት የድርጅትዎ የድጋፍ ኃላፊዎች ብቻ ናቸው።',
    'already_with_ethr' => 'ይህ ጥያቄ አስቀድሞ ወደ ETHR ድጋፍ ተልኳል።',
    'resolved_closed' => 'ይህ ጥያቄ ተፈቷል። እንደገና ለመክፈት መልስ ይጻፉ።',
    'message_required' => 'መጀመሪያ መልዕክት ይጻፉ።',
    'category' => [
        'sign_in' => 'መግባት',
        'attendance' => 'መገኘት እና ምዝገባ',
        'leave' => 'ፈቃድ',
        'payroll' => 'ደመወዝ እና የደመወዝ ወረቀት',
        'employees' => 'የሠራተኛ መዛግብት',
        'devices' => 'መሣሪያዎች እና ስልኮች',
        'billing' => 'ክፍያ እና እቅድ',
        'other' => 'ሌላ ጉዳይ',
    ],
    'notify' => [
        // In the app, with no translated label inside, so a notice reads in
        // the language the reader is using now (NotificationMessage).
        'in_app' => [
            'received' => 'በድርጅትዎ ያለ ሰው እርዳታ ጠይቋል።',
            'escalated' => 'አንድ ድርጅት የእርዳታ ጥያቄ ወደ ETHR አስተላልፏል።',
            'requester_replied' => 'በእርዳታ ጥያቄ ላይ አዲስ መልስ አለ።',
            'answered' => 'ለእርዳታ ጥያቄዎ መልስ ተሰጥቷል።',
            'passed_to_ethr' => 'የእርዳታ ጥያቄዎ ወደ ETHR ድጋፍ ተላልፏል።',
            'resolved' => 'የእርዳታ ጥያቄዎ እንደተፈታ ተመዝግቧል።',
        ],
        'open' => 'ጥያቄውን ይክፈቱ',
        'received' => 'በድርጅትዎ ያለ ሰው እርዳታ ጠይቋል፦ :category።',
        'escalated' => 'አንድ ድርጅት የእርዳታ ጥያቄ ወደ ETHR አስተላልፏል፦ :category።',
        'requester_replied' => 'በእርዳታ ጥያቄ ላይ አዲስ መልስ አለ፦ :category።',
        'answered' => 'ለእርዳታ ጥያቄዎ መልስ ተሰጥቷል፦ :category።',
        'passed_to_ethr' => 'የእርዳታ ጥያቄዎ ወደ ETHR ድጋፍ ተላልፏል፦ :category።',
        'resolved' => 'የእርዳታ ጥያቄዎ እንደተፈታ ተመዝግቧል፦ :category።',
    ],
];
