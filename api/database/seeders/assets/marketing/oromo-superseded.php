<?php

declare(strict_types=1);

/*
 * Built-in Oromo copy that an earlier release seeded and a later review
 * corrected, keyed as oromo.php keys it: a list of the earlier wordings.
 *
 * MarketingContentSeeder writes the corrected copy over a field that still
 * holds one of these, exactly as it writes into an empty one, and only while
 * the English beside it is still the seeded English. A field the operator
 * rewrote holds neither, so it is never touched.
 *
 * Append an entry whenever a value in oromo.php changes after it has shipped.
 */

return [
    'problems.items.paper_attendance.body' => [
        'Galmeen shiftii booda mallattaa\'a, sa\'aatiin dabalataa dhuma ji\'aatti falmisiisa, eenyutu sa\'aatii 8:02 irratti bakka hojii ture namni tokkollee hin beeku.',
    ],
    'problems.items.manual_payroll.body' => [
        'Ji\'a ji\'aan namni tokko sadarkaa gibira galii fi qoodinsa sooramaa shaqaa irratti herrega, seelii dogoggoraa tokko waraqaa mindaa irra ga\'a.',
    ],
    'problems.items.manual_payroll.detail' => [
        'Gibirri galii Labsii 1395/2025 jalatti, soorama fi qoodinsi Qaammee ofumaan raawwatamu; waraqaa mindaa hunda irratti karaan herregaa ni mul\'ata, namni tokkollee osoo hin argin dura ni raggaasifama.',
    ],
    'problems.items.leave_spreadsheets.title' => [
        'Haftee boqonnaa shaqaa irratti',
    ],
    'capabilities.items.records.body' => [
        'Hojjetaa hundaaf faayilii dijitaalaa guutuu, waliigaltee, sanadootaa fi yaadachiisa xumuraa waliin. Lakkoofsi TIN fi odeeffannoon baankii ni iccitiin eegama; muummeewwan, dameewwan, gitawwan hojii fi sadarkaaleen keessan chaartii caasaa ta\'u.',
    ],
    'trust.items.encryption.title' => [
        'Iccitii yeroo kuufamu',
    ],
    'trust.items.encryption.body' => [
        'Lakkoofsi herrega baankii, TIN fi eenyummaa biyyoolessaa AES-256\'n iccitiidhaan kuufamu.',
    ],
    'cta.subheading' => [
        'Kaardiin liqii hin barbaachisu. Amaloota hunda guutummaatti argattu.',
    ],
];
