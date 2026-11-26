<?php

declare(strict_types=1);

/*
 * The regional states and city administrations of Ethiopia, as organised since
 * 2023: twelve regional states plus Addis Ababa and Dire Dawa.
 *
 * Codes are this platform's own stable snake_case, not ISO 3166-2, which has
 * not issued codes for the newer regions. A code never changes once shipped;
 * a name may.
 *
 * Each row: code, English, Amharic, Afaan Oromoo, type.
 * Amharic and Afaan Oromoo names are awaiting a native reading
 * (docs/audit/BASELINE.md). Afaan Oromoo uses Finfinnee for Addis Ababa
 * (docs/LOCALIZATION.md).
 */
return [
    ['addis_ababa', 'Addis Ababa', 'አዲስ አበባ', 'Finfinnee', 'city_administration'],
    ['dire_dawa', 'Dire Dawa', 'ድሬዳዋ', 'Dirree Dawaa', 'city_administration'],
    ['afar', 'Afar', 'አፋር', 'Affaar', 'regional_state'],
    ['amhara', 'Amhara', 'አማራ', 'Amaaraa', 'regional_state'],
    ['benishangul_gumuz', 'Benishangul-Gumuz', 'ቤኒሻንጉል ጉሙዝ', 'Benishaangul-Gumuz', 'regional_state'],
    ['central_ethiopia', 'Central Ethiopia', 'ማዕከላዊ ኢትዮጵያ', 'Itoophiyaa Giddugaleessaa', 'regional_state'],
    ['gambela', 'Gambela', 'ጋምቤላ', 'Gambeellaa', 'regional_state'],
    ['harari', 'Harari', 'ሐረሪ', 'Hararii', 'regional_state'],
    ['oromia', 'Oromia', 'ኦሮሚያ', 'Oromiyaa', 'regional_state'],
    ['sidama', 'Sidama', 'ሲዳማ', 'Sidaamaa', 'regional_state'],
    ['somali', 'Somali', 'ሶማሌ', 'Somaalee', 'regional_state'],
    ['south_ethiopia', 'South Ethiopia', 'ደቡብ ኢትዮጵያ', 'Itoophiyaa Kibbaa', 'regional_state'],
    ['south_west_ethiopia', "South West Ethiopia Peoples'", 'ደቡብ ምዕራብ ኢትዮጵያ ሕዝቦች', 'Ummattoota Itoophiyaa Kibba Lixaa', 'regional_state'],
    ['tigray', 'Tigray', 'ትግራይ', 'Tigraay', 'regional_state'],
];
