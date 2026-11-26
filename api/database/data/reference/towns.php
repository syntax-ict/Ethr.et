<?php

declare(strict_types=1);

/*
 * The main towns of each region, keyed by region code. Not a gazetteer: the
 * regional capitals and the towns most organisations are based in. A town not
 * listed here is typed in, and nothing breaks.
 *
 * Each row: code, English, Amharic, Afaan Oromoo. Awaiting a native reading of
 * the Amharic and Afaan Oromoo names (docs/audit/BASELINE.md).
 */
return [
    'afar' => [
        ['semera', 'Semera', 'ሰመራ', 'Semeraa'],
        ['asaita', 'Asaita', 'አሳይታ', 'Asaayitaa'],
        ['awash', 'Awash', 'አዋሽ', 'Awaash'],
        ['dubti', 'Dubti', 'ዱብቲ', 'Dubtii'],
        ['logia', 'Logia', 'ሎጊያ', 'Logiyaa'],
    ],
    'amhara' => [
        ['bahir_dar', 'Bahir Dar', 'ባሕር ዳር', 'Baahir Daar'],
        ['gondar', 'Gondar', 'ጎንደር', 'Gondar'],
        ['dessie', 'Dessie', 'ደሴ', 'Deesee'],
        ['debre_markos', 'Debre Markos', 'ደብረ ማርቆስ', 'Debre Maarqoos'],
        ['debre_birhan', 'Debre Birhan', 'ደብረ ብርሃን', 'Debre Birhaan'],
        ['kombolcha', 'Kombolcha', 'ኮምቦልቻ', 'Kombolchaa'],
        ['woldia', 'Woldia', 'ወልዲያ', 'Woldiyaa'],
        ['debre_tabor', 'Debre Tabor', 'ደብረ ታቦር', 'Debre Taaboor'],
        ['lalibela', 'Lalibela', 'ላሊበላ', 'Laalibalaa'],
    ],
    'benishangul_gumuz' => [
        ['asosa', 'Asosa', 'አሶሳ', 'Asoosaa'],
        ['gilgel_beles', 'Gilgel Beles', 'ጊልጌል በለስ', 'Gilgel Belees'],
        ['pawe', 'Pawe', 'ፓዌ', 'Paawee'],
    ],
    'central_ethiopia' => [
        ['hosaena', 'Hosaena', 'ሆሳዕና', "Hosaa'inaa"],
        ['wolkite', 'Wolkite', 'ወልቂጤ', 'Wolqiteetii'],
        ['butajira', 'Butajira', 'ቡታጅራ', 'Butajiraa'],
        ['durame', 'Durame', 'ዱራሜ', 'Duraamee'],
        ['worabe', 'Worabe', 'ወራቤ', 'Warabee'],
        ['alaba_kulito', 'Alaba Kulito', 'አላባ ቁሊቶ', 'Alaabaa Kuliitoo'],
    ],
    'gambela' => [
        ['gambela_town', 'Gambela', 'ጋምቤላ', 'Gambeellaa'],
        ['itang', 'Itang', 'ኢታንግ', 'Itaang'],
    ],
    'harari' => [
        ['harar', 'Harar', 'ሐረር', 'Hararii'],
    ],
    'oromia' => [
        ['adama', 'Adama', 'አዳማ', 'Adaamaa'],
        ['jimma', 'Jimma', 'ጅማ', 'Jimmaa'],
        ['bishoftu', 'Bishoftu', 'ቢሾፍቱ', 'Bishooftuu'],
        ['shashamane', 'Shashamane', 'ሻሸመኔ', 'Shaashamannee'],
        ['nekemte', 'Nekemte', 'ነቀምቴ', 'Naqamtee'],
        ['asella', 'Asella', 'አሰላ', 'Asallaa'],
        ['ambo', 'Ambo', 'አምቦ', 'Amboo'],
        ['woliso', 'Woliso', 'ወሊሶ', 'Walisoo'],
        ['sebeta', 'Sebeta', 'ሰበታ', 'Sabbataa'],
        ['burayu', 'Burayu', 'ቡራዩ', 'Burraayyuu'],
        ['sululta', 'Sululta', 'ሱሉልታ', 'Sulultaa'],
        ['dukem', 'Dukem', 'ዱከም', 'Dukam'],
        ['robe', 'Robe', 'ሮቤ', 'Roobee'],
        ['goba', 'Goba', 'ጎባ', 'Gobbaa'],
        ['metu', 'Metu', 'መቱ', 'Mattuu'],
        ['gimbi', 'Gimbi', 'ጊምቢ', 'Gimbii'],
        ['yabelo', 'Yabelo', 'ያቤሎ', 'Yaabeelloo'],
        ['fiche', 'Fiche', 'ፍቼ', 'Fichee'],
        ['chiro', 'Chiro', 'ጭሮ', 'Ciroo'],
        ['negele_borana', 'Negele Borana', 'ነገሌ ቦረና', 'Nageellee Booranaa'],
    ],
    'sidama' => [
        ['hawassa', 'Hawassa', 'ሀዋሳ', 'Hawaasaa'],
        ['yirgalem', 'Yirgalem', 'ይርጋዓለም', 'Yirgaalem'],
        ['aleta_wondo', 'Aleta Wondo', 'አለታ ወንዶ', 'Aleeta Wondoo'],
    ],
    'somali' => [
        ['jijiga', 'Jijiga', 'ጅጅጋ', 'Jijjiiga'],
        ['gode', 'Gode', 'ጎዴ', 'Godee'],
        ['kebri_dahar', 'Kebri Dahar', 'ቀብሪ ዳሃር', 'Qabri Dahaar'],
        ['degehabur', 'Degehabur', 'ደገሃቡር', 'Dagahaabuur'],
    ],
    'south_ethiopia' => [
        ['wolaita_sodo', 'Wolaita Sodo', 'ወላይታ ሶዶ', 'Walaayitaa Soddoo'],
        ['arba_minch', 'Arba Minch', 'አርባ ምንጭ', 'Arba Minci'],
        ['dilla', 'Dilla', 'ዲላ', 'Dillaa'],
        ['jinka', 'Jinka', 'ጂንካ', 'Jinkaa'],
        ['karat', 'Karat (Konso)', 'ካራት', 'Karaat'],
    ],
    'south_west_ethiopia' => [
        ['bonga', 'Bonga', 'ቦንጋ', 'Bonga'],
        ['mizan_aman', 'Mizan Aman', 'ሚዛን አማን', 'Mizaan Amaan'],
        ['tepi', 'Tepi', 'ቴፒ', 'Teppii'],
    ],
    'tigray' => [
        ['mekelle', 'Mekelle', 'መቐለ', 'Mekelle'],
        ['adigrat', 'Adigrat', 'አዲግራት', 'Adigraat'],
        ['axum', 'Axum', 'አክሱም', 'Aksum'],
        ['shire', 'Shire', 'ሽሬ', 'Shire'],
        ['adwa', 'Adwa', 'አድዋ', 'Adwa'],
        ['alamata', 'Alamata', 'አላማጣ', 'Alamaata'],
        ['humera', 'Humera', 'ሑመራ', 'Humeraa'],
    ],
];
