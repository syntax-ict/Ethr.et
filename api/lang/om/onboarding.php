<?php

declare(strict_types=1);

return [
    // Decisions the configuration review step asks the admin to confirm. Each is
    // a default that is legally or financially sensitive, so a template proposes
    // it and says so instead of applying it as if it were settled.
    'review' => [
        'leave_private' => 'Gosoonni boqonnaa gatiiwwan ETHR yaada dhiyeesse fayyadamu (waggaa guyyaa 16, dhukkubaa 30, da\'umsaa 120, abbummaa 3, fuudhaa fi heerumaa fi gaddaa 3). Labsiin Hojjetaa fi Hojjechiisaa 1156/2019 tajaajila waggaa lama lamaaf boqonnaa waggaa guyyaa tokko dabala, boqonnaa dhukkubaas sadarkaadhaan kaffala; ETHR isaan lamaanuu ofumaan hin herregu. Hojjettoonni boqonnaa gaafachuu isaanii dura gatii tokkoon tokkoo mirkaneessaa.',
        'leave_public' => 'Hojjettoonni siivilii Labsii Hojjettoota Siivilii Federaalaa 1064/2017 ykn seera tajaajila siivilii naannootiin bulu, Labsii Hojjetaa fi Hojjechiisaatiin miti. Gatiiwwan boqonnaa yaada dhiyaatan durtii waliigalaa ETHR ti malee seerota sana irraa kan fudhataman miti. Tokkoon tokkoo isaanii seera isin ilaallatu waliin mirkaneessaa.',
        'leave_custom' => 'Boqonnaa waggaa, dhukkubaa fi kaffaltii malee qofatu, guyyoota durtii waliigalaa ETHR waliin, yaada dhiyaate. Mirga imaammanni keessan kennu qindeessaa.',
        'payroll_public' => 'Iskeeliin mindaa fi abbaltiiwwan mootummaa komishinii tajaajila siiviliitiin murtaa\'u. ETHR isaan hin fe\'u: kaffaltii mindaa hojjechiisuu dura iskeelii keessan sadarkaalee fi abbaltiiwwan irratti galchaa.',
        'allowances_inactive' => 'Abbaltiiwwan cufamanii hanga zeeroon uumamu. Abbaltiin hojii irra jiru hojjetaa hundaaf kaffalama, kanaaf banuu dura hanga isaa fi gibira kan qabu ta\'uu isaa qindeessaa (abbaltiin geejjibaa fi kanneen biroo tokko tokko daangaa bilisaa qabu).',
        'grades_illustrative' => 'Hangi mindaa sadarkaalee qabxii jalqabaa fakkeenyaaf damee sanaaf ta\'an malee gatii gabaa ykn seeraa miti. Hojjettoota walqabsiisuu dura ilaalaa. Tempileetiin mindaa nama kamiyyuu hin murteessu.',
        'statutory_engine' => 'Gibirri galii fi gumaachi sooramaa motora kaffaltii mindaatiin gabatee mataa isaa irraa hojiirra oola. Tempileetiin isaan hin jijjiiru.',
        'flexible_is_grace' => 'ETHR moodeela sa\'aatii bu\'uuraa (core hours) hin qabu. Jalqabni jijjiiramaa yeroo dhiifamaa sa\'aatii lamaatiin dhihaatee fakkeeffama, kanaaf sa\'aatii 10:00 dura dhufuun akka barfateetti hin mallattaa\'u; sa\'aatiin garuu seensa fi bahiinsa dhugaa irraa lakkaa\'ama.',
        'seasonal_contracts' => 'Hojjettoonni yeroo (waqtii) irratti hundaa\'an yeroo baay\'ee waliigaltee yeroo murtaa\'e irra jiru. Waliigaltee hojjetaa tokkoon tokkoo fi guyyaa xumura isaa galmee hojjetaa isaanii irratti galmeessaa; tempileetiin waliigaltee hin uumu.',
        'rotation_crews' => 'Marsaan 2-2-2-2 shiftii hunda kan haguugu yoo gareewwan afur, guyyoonni jalqabaa isaanii guyyaa lamaan wal irraa fagaatanii, itti ramadaman qofa.',
    ],

    // The People step of the guided setup.
    'migration' => [
        'duplicate_code' => 'Toorri lama lakkoofsa hojjetaa tokkicha qabu. Namni tokkoon tokkoo kan mataa isaa barbaada.',
        'duplicate_email' => 'Toorri lama teessoo imeelii tokkicha qabu. Namni tokkoon tokkoo kan mataa isaa barbaada.',
        'already_committing' => 'Tarreen kun dabalamaa jira. Hanga xumuramutti eegaa.',
        'accept_needs_match' => 'Jijjiirama fudhachuu dura sararri kun hojjetaa kam akka ta\'e filadhaa.',
        'device_user' => 'Fayyadamaa meeshaa :id',
        'line' => 'Sarara :line: :message',
        'nobody' => 'Tarree kana keessa namni hin jiru.',
        'too_many' => 'Tarreen kun namoota :count qaba. Yeroo tokkotti hanga :max qofa galchaa.',
        'relation_not_found' => ':field ":value" hin argamne. Koodii isaa ykn maqaa isaa akkuma ETHR keessatti mul\'atutti fayyadamaa.',
        'relation_ambiguous' => ':field tokkoo ol ":value" jedhama. Qooda kanaa koodii isaa fayyadamaa.',
        'duplicate_in_list' => ':field ":value" sarara :first irras jira. Namni tokkoon tokkoo kan mataa isaa barbaada.',
    ],
];
