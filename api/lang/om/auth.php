<?php

declare(strict_types=1);

return [
    'failed' => 'Ragaaleen kun galmee keenya waliin wal hin simne.',
    'throttle' => 'Yaaliin seenuu baay\'eeteera. Maaloo sekondii :seconds booda irra deebi\'aa yaalaa.',
    'logout_success' => 'Milkaa\'inaan ba\'ameera.',
    'mfa_required' => 'Mirkaneessi tarkaanfii hedduu barbaachisaadha.',
    'mfa_invalid' => 'Koodiin mirkaneessaa sirrii miti.',
    'mfa_enabled' => 'Mirkaneessi tarkaanfii lamaa banameera.',
    'mfa_disabled' => 'Mirkaneessi tarkaanfii lamaa cufameera.',
    'account_inactive' => 'Herregni keessan hojii irra hin jiru.',
    'account_suspended' => 'Herregni keessan yeroof dhorkameera.',
    'canonical_address' => 'Dhaabbanni keessan teessoo mataa isaatiin seena. Gara sanatti isin geessaa jirra.',
    'impersonation_restricted' => 'Utuu dhaabbata bakka bu\'aa jirtanii tarkaanfiin kun hin hayyamamu.',
    'not_impersonating' => 'Seshiniin bakka bu\'iinsaa hojii irra jiru hin jiru.',
    'impersonation_ended' => 'Seshiniin bakka bu\'iinsaa xumurameera.',
    'sso_not_configured' => 'Seensi tokkichaa (SSO) dhaabbata kanaaf hin qindoofne.',

    // Account lockout alerting
    'lockout_alert_title' => 'Herregni Erga Seensi Irra Deddeebiin Hin Milkoofne Booda Cufame',
    'lockout_alert_body' => 'Herregni ":identifier" erga IP :ip irraa yaaliin seenuu irra deddeebiin hin milkoofne booda daqiiqaa :minutes cufameera.',

    // Session management
    'session_revoked' => 'Seshiniin haqameera.',
    'sessions_revoked' => 'Seshiniiwwan biroo hunda irraa ba\'ameera.',
    'session_not_found' => 'Seshiniin sun amma hin jiru.',

    // Password policy
    'password_too_short' => 'Jechi icciitii yoo xiqqaate qubee :min qabaachuu qaba.',
    'password_needs_uppercase' => 'Jechi icciitii yoo xiqqaate qubee guddaa tokko qabaachuu qaba.',
    'password_needs_lowercase' => 'Jechi icciitii yoo xiqqaate qubee xiqqaa tokko qabaachuu qaba.',
    'password_needs_number' => 'Jechi icciitii yoo xiqqaate lakkoofsa tokko qabaachuu qaba.',
    'password_needs_symbol' => 'Jechi icciitii yoo xiqqaate mallattoo tokko qabaachuu qaba.',

    // OTP
    'otp_sent' => 'Herregni yoo jiraate, koodiin mirkaneessaa ergameera.',
    'otp_invalid' => 'Koodiin mirkaneessaa sun sirrii miti ykn yeroon isaa darbeera.',
    'otp_unavailable' => 'Koodiiwwan mirkaneessaa erguun hin danda\'amu — karri SMS hin qindoofne.',
    'otp_message' => 'Koodiin mirkaneessaa ETHR keessanii :code. Daqiiqaa :minutes keessatti yeroon isaa darba.',
    'otp_email_unavailable' => 'Koodiiwwan seensaa imeeliin erguun hin danda\'amu — sarvarri imeelii hin qindoofne.',
    'otp_email_paused' => 'Koodiiwwan seensaa imeeliin ergaman yeroof cufamaniiru. Jecha icciitii keessaniin seenaa.',
    'sms_mobile_only' => 'Koodiiwwan ergaa barreeffamaa lakkoofsa bilbila harkaa (9 ykn 7 kan jalqaban) qofaaf ergamu. Lakkoofsa bilbila harkaa keessanii fayyadamaa, ykn koodii imeeliin argadhaa.',
    'otp_email_subject' => 'Koodii seensaa ETHR keessanii: :code',
    'otp_email_intro' => 'ETHR irratti :organisation seenuuf koodii kana fayyadamaa:',
    'otp_email_expiry' => 'Daqiiqaa :minutes keessatti yeroon isaa darba, al tokko qofas hojjeta.',
    'otp_email_ignore' => 'Yoo seenuu hin gaafanne, imeelii kana bira darbaa. Namni sanduuqa imeelii keessanii argachuu malee koodii kana fayyadamuu hin danda\'u.',

    // Trusted devices

    'mfa_incomplete' => 'Herrega kana fayyadamuu dura mirkaneessa tarkaanfii lamaa xumuraa.',

    // Platform console
    'platform_mfa_required' => 'Konsoolii waltajjii irratti jijjiirama gochuu dura mirkaneessi tarkaanfii hedduu herrega keessan irratti banamuu qaba. Piroofaayilii > Nageenya jalatti qindeessaa.',

    // Tenant security policy (audit N6)
    'mfa_enrolment_required' => 'Dhaabbanni keessan mirkaneessa tarkaanfii lamaa barbaada. Itti fufuuf Piroofaayilii > Nageenya jalatti qindeessaa.',
    'mfa_disabled_by_policy' => 'Dhaabbanni keessan mirkaneessa tarkaanfii lamaa hin dhiyeessu.',
    'session_idle_expired' => 'Seshiniin keessan erga yeroo tokkoof hojiin hin jirre booda xumurameera. Irra deebi\'aa seenaa.',

    // "Find my organisation" on the apex login (2026-10-04)
    'find_organisation' => [
        'sent' => 'Teessoon sun kan dhaabbata tokkoo yoo ta\'e, liinkii seensaa isaa imeeliin isinii ergineerra.',
        'too_many' => 'Teessoo kanaaf gaaffiin baay\'eeteera. Daqiiqaa muraasa booda irra deebi\'aa yaalaa.',
        'mail_subject' => 'Liinkii seensaa ETHR keessanii',
        'mail_intro' => 'Teessoon kun ETHR irratti dhaabbilee kamitti seenuu akka danda\'u gaafattaniittu. Liinkii isa barbaaddanii fayyadamaa:',
        'mail_action' => 'Seeni',
        'mail_ignore' => 'Yoo kana hin gaafanne, imeelii kana bira darbuu dandeessu; homaa hin jijjiiramne.',
    ],

    // Sent when an organisation registers (production QA 2026-10-11, P7)
    'registered' => [
        'mail_subject' => ':org ETHR irratti qophaa\'eera',
        'mail_greeting' => 'Baga gara ETHR dhuftan',
        'mail_intro' => 'Dhaabbanni ETHR, :org, teessoo imeelii kanaan akka bulchaatti uumameera.',
        'mail_address' => 'Dhaabbanni keessan :url irratti seena — kuusadhaa (bookmark).',
        'mail_action' => ':org seeni',
        'mail_not_you' => 'Yoo dhaabbata kana hin uumne, :contact irratti nutti himaa, nuti ni haqna.',
    ],

    // In-person activation (users with no email or phone)
    'activation_refused' => [
        'tenant' => 'Hojjetaan sun dhaabbata kana keessa hin jiru.',
        'document' => 'Sanada eenyummaa mirkaneessitan filadhaa.',
        'self' => 'Herrega mataa keessaniif koodii kakaasaa kennuu hin dandeessan.',
        'role' => 'Herrega sadarkaa keessan irra ykn ol jiruuf koodii kakaasaa kennuu hin dandeessan.',
        'status' => 'Herregni kun haala amma irra jirutti kakaafamuu ykn deebifamuu hin danda\'u.',
        'contact' => 'Hojjetaan kun imeeliin ykn ergaa barreeffamaatiin argamuu danda\'a. Affeerraa idilee ykn jecha icciitii haaromsuu fayyadamaa.',
        'identifier' => 'Hojjetaan kun karaa itti seenu hin qabaatu. Jalqaba qindaa\'ina keessatti seensa koodii hojjetaatiin banaa.',
    ],
];
