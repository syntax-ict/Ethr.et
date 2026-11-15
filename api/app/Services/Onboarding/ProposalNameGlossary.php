<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Amharic and Afaan Oromoo for the departments, positions and grades the
 * setup wizard proposes.
 *
 * The industry catalogue and the eight templates name these in English only,
 * as plain strings, so the proposal read in English on an Amharic or Oromo
 * screen (QA 2026-11-14, O-2). The names stay where they are, and this is the
 * one place their translations live: IndustryProfileResolver looks each name
 * up here and hands the proposal `name`, `name_am` and `name_om` (positions:
 * `title`, `title_am`, `title_om`), the shape the other lists already have.
 *
 * What is stored on apply: `departments.name_am` and `positions.title_am`,
 * which those tables have. Neither has an Oromo column and `grades` has
 * neither, so the Oromo names, and both for a grade, are what the proposal
 * shows and nothing more.
 *
 * A name with no entry is returned in English alone. ProposalNameGlossaryTest
 * fails when a catalogue or template name has none, so a new department is
 * added here with it.
 */
final class ProposalNameGlossary
{
    /** @var array<string, array{0: string, 1: string}> English => [Amharic, Afaan Oromoo] */
    private const DEPARTMENTS = [
        "Minister's Office" => ['የሚኒስትር ጽሕፈት ቤት', 'Waajjira Ministeeraa'],
        "State Ministers' Offices" => ['የሚኒስትር ዴኤታዎች ጽሕፈት ቤቶች', 'Waajjiraalee Ministir Deetaa'],
        'Policy & Planning' => ['ፖሊሲና ዕቅድ', 'Imaammataa fi Karoora'],
        'Finance & Procurement' => ['ፋይናንስና ግዥ', 'Faayinaansii fi Bittaa'],
        'Human Resource Management' => ['የሰው ሀብት አስተዳደር', 'Bulchiinsa Qabeenya Namaa'],
        'Legal Services' => ['የሕግ አገልግሎት', 'Tajaajila Seeraa'],
        'Internal Audit' => ['የውስጥ ኦዲት', 'Odiitii Keessaa'],
        'ICT' => ['አይሲቲ', 'Teeknooloojii Odeeffannoo fi Quunnamtii (ICT)'],
        'Ethics & Anti-Corruption' => ['ሥነ ምግባርና ፀረ ሙስና', 'Naamusaa fi Farra Malaammaltummaa'],
        'Public Relations & Communication' => ['የሕዝብ ግንኙነትና ኮሙኒኬሽን', 'Hariiroo Uummataa fi Kominikeeshinii'],
        'Women & Social Inclusion' => ['የሴቶችና ማኅበራዊ አካታችነት', 'Dubartootaa fi Hammattummaa Hawaasummaa'],
        "Director General's Office" => ['የዋና ዳይሬክተር ጽሕፈት ቤት', 'Waajjira Daarektara Olaanaa'],
        'Operations Directorate' => ['የኦፕሬሽን ዳይሬክቶሬት', 'Daarektoreetii Hojmaataa'],
        'Planning, Monitoring & Evaluation' => ['ዕቅድ፣ ክትትልና ግምገማ', 'Karoora, Hordoffii fi Gamaaggama'],
        'Public Relations' => ['የሕዝብ ግንኙነት', 'Hariiroo Uummataa'],
        "Bureau Head's Office" => ['የቢሮ ኃላፊ ጽሕፈት ቤት', 'Waajjira Hoogganaa Biiroo'],
        'Core Programme Directorate' => ['የዋና ፕሮግራም ዳይሬክቶሬት', 'Daarektoreetii Sagantaa Ijoo'],
        "Zone Administrator's Office" => ['የዞን አስተዳዳሪ ጽሕፈት ቤት', 'Waajjira Bulchaa Godinaa'],
        'Finance Department' => ['የፋይናንስ መምሪያ', 'Qajeelcha Faayinaansii'],
        'Plan & Development Department' => ['የፕላንና ልማት መምሪያ', 'Qajeelcha Karooraa fi Misoomaa'],
        'Civil Service Department' => ['የሲቪል ሰርቪስ መምሪያ', 'Qajeelcha Siivil Sarviisii'],
        'Education Department' => ['የትምህርት መምሪያ', 'Qajeelcha Barnootaa'],
        'Health Department' => ['የጤና መምሪያ', 'Qajeelcha Fayyaa'],
        'Agriculture Department' => ['የግብርና መምሪያ', 'Qajeelcha Qonnaa'],
        'Peace & Security Department' => ['የሰላምና ጸጥታ መምሪያ', 'Qajeelcha Nageenyaa fi Tasgabbii'],
        'Women & Social Affairs Department' => ['የሴቶችና ማኅበራዊ ጉዳይ መምሪያ', 'Qajeelcha Dhimma Dubartootaa fi Hawaasummaa'],
        "Mayor's Office" => ['የከንቲባ ጽሕፈት ቤት', 'Waajjira Kantiibaa'],
        "City Manager's Office" => ['የከተማ ሥራ አስኪያጅ ጽሕፈት ቤት', 'Waajjira Hojii Gaggeessaa Magaalaa'],
        'Finance' => ['ፋይናንስ', 'Faayinaansii'],
        'Revenue' => ['ገቢዎች', 'Galiiwwan'],
        'Land Development & Management' => ['የመሬት ልማትና ማኔጅመንት', 'Misoomaa fi Bulchiinsa Lafaa'],
        'Urban Planning' => ['የከተማ ፕላን', 'Karoora Magaalaa'],
        'Construction & Infrastructure' => ['ኮንስትራክሽንና መሠረተ ልማት', "Ijaarsaa fi Bu'uuraalee Misoomaa"],
        'Trade' => ['ንግድ', 'Daldala'],
        'Civil Registration' => ['የሲቪል ምዝገባ', 'Galmeessa Siivilii'],
        'Sanitation & Beautification' => ['ጽዳትና ውበት', 'Qulqullinaa fi Miidhagina'],
        'Peace & Security' => ['ሰላምና ጸጥታ', 'Nageenyaa fi Tasgabbii'],
        "Woreda Administrator's Office" => ['የወረዳ አስተዳዳሪ ጽሕፈት ቤት', 'Waajjira Bulchaa Aanaa'],
        'Finance Office' => ['የፋይናንስ ጽሕፈት ቤት', 'Waajjira Faayinaansii'],
        'Plan & Development Office' => ['የፕላንና ልማት ጽሕፈት ቤት', 'Waajjira Karooraa fi Misoomaa'],
        'Civil Service Office' => ['የሲቪል ሰርቪስ ጽሕፈት ቤት', 'Waajjira Siivil Sarviisii'],
        'Education Office' => ['የትምህርት ጽሕፈት ቤት', 'Waajjira Barnootaa'],
        'Health Office' => ['የጤና ጽሕፈት ቤት', 'Waajjira Fayyaa'],
        'Agriculture Office' => ['የግብርና ጽሕፈት ቤት', 'Waajjira Qonnaa'],
        'Women, Children & Youth Office' => ['የሴቶች፣ ሕፃናትና ወጣቶች ጽሕፈት ቤት', "Waajjira Dubartootaa, Daa'immanii fi Dargaggootaa"],
        'Trade & Market Development Office' => ['የንግድና ገበያ ልማት ጽሕፈት ቤት', 'Waajjira Daldalaa fi Misooma Gabaa'],
        'Peace & Security Office' => ['የሰላምና ጸጥታ ጽሕፈት ቤት', 'Waajjira Nageenyaa fi Tasgabbii'],
        'Land Administration Office' => ['የመሬት አስተዳደር ጽሕፈት ቤት', 'Waajjira Bulchiinsa Lafaa'],
        'Revenue Office' => ['የገቢዎች ጽሕፈት ቤት', 'Waajjira Galiiwwanii'],
        "Office Head's Office" => ['የጽሕፈት ቤት ኃላፊ ቢሮ', 'Biiroo Hoogganaa Waajjiraa'],
        'Core Service Unit' => ['የዋና አገልግሎት ክፍል', 'Kutaa Tajaajila Ijoo'],
        'Planning & Reporting' => ['ዕቅድና ሪፖርት', 'Karooraa fi Gabaasa'],
        'Support Services' => ['የድጋፍ አገልግሎቶች', 'Tajaajiloota Deeggarsaa'],
        'Academic Affairs' => ['የአካዳሚክ ጉዳዮች', 'Dhimmoota Akkaadaamii'],
        'Workshops & Labs' => ['ወርክሾፖችና ላቦራቶሪዎች', 'Workishooppii fi Laaboraatoorii'],
        'Cooperative Training' => ['የትብብር ሥልጠና', 'Leenjii Waloo'],
        'Student Services' => ['የተማሪዎች አገልግሎት', 'Tajaajila Barattootaa'],
        'Registrar' => ['ሬጅስትራር', 'Galmeessaa (Rejistraara)'],
        'HR' => ['የሰው ኃይል', 'Qabeenya Namaa (HR)'],
        'Facilities' => ['ፋሲሊቲዎች', 'Faasilitii'],
        'Academics' => ['አካዳሚክ', 'Akkaadaamii'],
        'Student Affairs' => ['የተማሪዎች ጉዳይ', 'Dhimma Barattootaa'],
        'Administration' => ['አስተዳደር', 'Bulchiinsa'],
        'Library' => ['ቤተ መጻሕፍት', 'Mana Kitaabaa'],
        'Outpatient' => ['ተመላላሽ ሕክምና', 'Yaala Deddeebii'],
        'Emergency' => ['ድንገተኛ ሕክምና', 'Yaala Hatattamaa'],
        'Pharmacy' => ['ፋርማሲ', 'Faarmaasii'],
        'Laboratory' => ['ላቦራቶሪ', 'Laaboraatoorii'],
        'Nursing' => ['ነርሲንግ', 'Narsummaa'],
        'Underwriting' => ['አንደርራይቲንግ', 'Waliigaltee Inshuraansii (Underwriting)'],
        'Claims' => ['የካሳ ጥያቄዎች', 'Gaaffii Beenyaa'],
        'Actuarial' => ['አክቹዋሪያል', 'Herrega Balaa (Actuarial)'],
        'Reinsurance' => ['የጠለፋ ዋስትና', 'Inshuraansii Irra Deebii'],
        'Sales & Marketing' => ['ሽያጭና ማርኬቲንግ', 'Gurgurtaa fi Gabaa'],
        'Risk & Compliance' => ['ሥጋትና ተገዢነት', 'Balaa fi Seeraan Hojjechuu'],
        'IT' => ['አይቲ', 'Teeknooloojii Odeeffannoo (IT)'],
        'Loan Operations' => ['የብድር ኦፕሬሽን', 'Hojmaata Liqii'],
        'Savings' => ['ቁጠባ', 'Qusannoo'],
        'Credit Appraisal' => ['የብድር ግምገማ', 'Madaallii Liqii'],
        'Collections' => ['የብድር አሰባሰብ', 'Sassaabbii Liqii'],
        'Branch Operations' => ['የቅርንጫፍ ኦፕሬሽን', 'Hojmaata Damee'],
        'Compliance' => ['ተገዢነት', 'Seeraan Hojjechuu'],
        'Site Operations' => ['የሳይት ሥራዎች', 'Hojii Saayitii'],
        'Project Management' => ['የፕሮጀክት አስተዳደር', 'Bulchiinsa Pirojektii'],
        'Engineering' => ['ምህንድስና', 'Injinariingii'],
        'Safety (HSE)' => ['የሥራ ደህንነት (HSE)', 'Nageenya Hojii (HSE)'],
        'Procurement' => ['ግዥ', 'Bittaa'],
        'Equipment & Machinery' => ['መሣሪያዎችና ማሽነሪ', 'Meeshaalee fi Maashinoota'],
        'Farm Operations' => ['የእርሻ ሥራዎች', 'Hojii Qonnaa'],
        'Irrigation' => ['መስኖ', 'Jallisii'],
        'Agronomy' => ['አግሮኖሚ', 'Agronoomii'],
        'Harvest & Processing' => ['ምርት መሰብሰብና ማቀነባበር', 'Sassaabbii Oomishaa fi Qopheessuu'],
        'Logistics' => ['ሎጂስቲክስ', 'Loojistiksii'],
        'Machinery' => ['ማሽነሪ', 'Maashinoota'],
        'Kitchen' => ['ወጥ ቤት', 'Kushiinaa'],
        'Service' => ['መስተንግዶ', 'Keessummeessuu'],
        'Bar' => ['ባር', 'Baarii'],
        'Catering Operations' => ['የኬተሪንግ ሥራዎች', 'Hojii Nyaata Qopheessuu (Catering)'],
        'Purchasing & Stores' => ['ግዥና መጋዘን', 'Bittaa fi Kuusaa'],
        'Store Operations' => ['የሱቅ ሥራዎች', 'Hojii Suuqii'],
        'Sales' => ['ሽያጭ', 'Gurgurtaa'],
        'Inventory & Stock' => ['የዕቃ ክምችት', 'Kuusaa Meeshaalee'],
        'Cashiering' => ['የገንዘብ ተቀባይ ክፍል', 'Kutaa Qarshii Fudhataa'],
        'Customer Service' => ['የደንበኞች አገልግሎት', 'Tajaajila Maamiltootaa'],
        'Warehouse' => ['መጋዘን', 'Mana Kuusaa'],
        'Distribution' => ['ስርጭት', 'Raabsa'],
        'Fleet' => ['የተሽከርካሪ ስምሪት', 'Konkolaattota'],
        "Partners' Office" => ['የአጋሮች ጽሕፈት ቤት', 'Waajjira Paartinarootaa'],
        'Advisory & Consulting' => ['የማማከር አገልግሎት', 'Tajaajila Gorsaa'],
        'Audit & Assurance' => ['ኦዲትና ማረጋገጫ', 'Odiitii fi Mirkaneessa'],
        'Business Development' => ['የቢዝነስ ልማት', 'Misooma Daldalaa'],
        'Operations' => ['ኦፕሬሽኖች', 'Hojmaata'],
        'Fleet Management' => ['የተሽከርካሪ ስምሪት አስተዳደር', 'Bulchiinsa Konkolaattotaa'],
        'Warehousing' => ['የመጋዘን አገልግሎት', 'Tajaajila Mana Kuusaa'],
        'Customs & Clearing' => ['ጉምሩክና ትራንዚት', 'Gumurukaa fi Tiraanziitii'],
        'Fleet Maintenance' => ['የተሽከርካሪ ጥገና', 'Suphaa Konkolaattotaa'],
        'Route Planning' => ['የመስመር ዕቅድ', 'Karoora Daandii'],
        'Ticketing' => ['የትኬት ሽያጭ', 'Gurgurtaa Tikeetii'],
        'Safety' => ['የሥራ ደህንነት', 'Nageenya Hojii'],
        'Network Operations' => ['የኔትወርክ ኦፕሬሽን', 'Hojmaata Neetworkii'],
        'Billing' => ['የክፍያ መጠየቂያ', 'Kaffaltii Gaafachuu (Billing)'],
        'Guarding Operations' => ['የጥበቃ ሥራዎች', 'Hojii Eegumsaa'],
        'Cleaning & Facilities' => ['ጽዳትና ፋሲሊቲ', 'Qulqullinaa fi Faasilitii'],
        'Training' => ['ሥልጠና', 'Leenjii'],
        'Client Relations' => ['የደንበኞች ግንኙነት', 'Hariiroo Maamiltootaa'],
        'Control Room' => ['መቆጣጠሪያ ክፍል', "Kutaa To'annoo"],
        'Contact Center' => ['የጥሪ ማዕከል', 'Giddugala Bilbilaa'],
        'Quality Assurance' => ['የጥራት ማረጋገጫ', 'Mirkaneessa Qulqullinaa'],
        'Workforce Management' => ['የሰው ኃይል ስምሪት', 'Bulchiinsa Humna Namaa'],
        'Product' => ['ፕሮዳክት', 'Oomisha (Product)'],
        'Design' => ['ዲዛይን', 'Dizaayinii'],
        'QA' => ['የጥራት ማረጋገጫ (QA)', 'Mirkaneessa Qulqullinaa (QA)'],
        'DevOps' => ['ዴቭኦፕስ', 'Hojmaata Sirnootaa (DevOps)'],
        'Customer Success' => ['የደንበኞች ስኬት', "Milkaa'ina Maamiltootaa"],
        'Management' => ['ማኔጅመንት', 'Hoggansa'],
        'Finance & Administration' => ['ፋይናንስና አስተዳደር', 'Faayinaansii fi Bulchiinsa'],
        'Executive Office' => ['የሥራ አስፈጻሚ ጽሕፈት ቤት', 'Waajjira Hojii Raawwachiisaa'],
        'Regional Coordination' => ['የክልል ማስተባበሪያ', 'Qindeessa Naannoo'],
        'Human Resources' => ['የሰው ኃይል', 'Qabeenya Namaa'],
        'Procurement & Logistics' => ['ግዥና ሎጂስቲክስ', 'Bittaa fi Loojistiksii'],
        'Legal' => ['ሕግ', 'Seera'],
        'Planning & Development' => ['ዕቅድና ልማት', 'Karooraa fi Misooma'],
        'Credit' => ['ብድር', 'Liqii'],
        'Treasury' => ['ትሬዠሪ', 'Qabeenya Maallaqaa (Treasury)'],
        'Risk Management' => ['የሥጋት አስተዳደር', 'Bulchiinsa Balaa'],
        'Marketing' => ['ማርኬቲንግ', 'Gabaa (Marketing)'],
        'Audit' => ['ኦዲት', 'Odiitii'],
        'Surgery' => ['ቀዶ ሕክምና', 'Baqaqsanii Yaaluu'],
        'Internal Medicine' => ['የውስጥ ደዌ ሕክምና', 'Yaala Dhukkuba Keessaa'],
        'Pediatrics' => ['የሕፃናት ሕክምና', "Yaala Daa'immanii"],
        'OB/GYN' => ['የማህፀንና ጽንስ ሕክምና', "Yaala Da'umsaa fi Gadameessaa"],
        'Radiology' => ['ራዲዮሎጂ', 'Raadiyooloojii'],
        'Production' => ['ምርት', 'Oomisha'],
        'Quality Control' => ['የጥራት ቁጥጥር', "To'annoo Qulqullinaa"],
        'Maintenance' => ['ጥገና', 'Suphaa'],
        'Programs' => ['ፕሮግራሞች', 'Sagantaalee'],
        'Finance & Grants' => ['ፋይናንስና ግራንት', 'Faayinaansii fi Deeggarsa Maallaqaa'],
        'HR & Admin' => ['የሰው ኃይልና አስተዳደር', 'Qabeenya Namaa fi Bulchiinsa'],
        'M&E' => ['ክትትልና ግምገማ', 'Hordoffii fi Gamaaggama'],
        'Communications' => ['ኮሙኒኬሽን', 'Kominikeeshinii'],
        'Field Operations' => ['የመስክ ሥራዎች', 'Hojii Dirree'],
        'Front Office' => ['እንግዳ መቀበያ', 'Simannaa Keessummaa'],
        'Housekeeping' => ['የቤት አያያዝ', 'Qabiinsa Manaa'],
        'F&B Service' => ['የምግብና መጠጥ መስተንግዶ', 'Tajaajila Nyaataa fi Dhugaatii'],
        'Security' => ['ጥበቃ', 'Eegumsa'],
        'Research' => ['ምርምር', 'Qorannoo'],
        'IT Center' => ['የአይቲ ማዕከል', 'Giddugala IT'],
    ];

    /** @var array<string, array{0: string, 1: string}> English => [Amharic, Afaan Oromoo] */
    private const POSITIONS = [
        'Minister' => ['ሚኒስትር', 'Ministeera'],
        'State Minister' => ['ሚኒስትር ዴኤታ', 'Ministir Deetaa'],
        'Chief Executive Officer' => ['ዋና ሥራ አስፈጻሚ', 'Hojii Raawwachiisaa Olaanaa'],
        'Director General' => ['ዋና ዳይሬክተር', 'Daarektara Olaanaa'],
        'Deputy Director General' => ['ምክትል ዋና ዳይሬክተር', 'Itti Aanaa Daarektara Olaanaa'],
        'Bureau Head' => ['የቢሮ ኃላፊ', 'Hoogganaa Biiroo'],
        'Deputy Bureau Head' => ['ምክትል የቢሮ ኃላፊ', 'Itti Aanaa Hoogganaa Biiroo'],
        'Zone Administrator' => ['የዞን አስተዳዳሪ', 'Bulchaa Godinaa'],
        'Deputy Administrator' => ['ምክትል አስተዳዳሪ', 'Itti Aanaa Bulchaa'],
        'Department Head' => ['የመምሪያ ኃላፊ', 'Hoogganaa Qajeelchaa'],
        'Mayor' => ['ከንቲባ', 'Kantiibaa'],
        'Deputy Mayor' => ['ምክትል ከንቲባ', 'Itti Aanaa Kantiibaa'],
        'City Manager' => ['የከተማ ሥራ አስኪያጅ', 'Hojii Gaggeessaa Magaalaa'],
        'Woreda Administrator' => ['የወረዳ አስተዳዳሪ', 'Bulchaa Aanaa'],
        'Office Head' => ['የጽሕፈት ቤት ኃላፊ', 'Hoogganaa Waajjiraa'],
        'Dean' => ['ዲን', 'Diinii'],
        'Senior Instructor' => ['ከፍተኛ አሠልጣኝ', 'Leenjisaa Olaanaa'],
        'Instructor' => ['አሠልጣኝ', 'Leenjisaa'],
        'Assistant Instructor' => ['ረዳት አሠልጣኝ', 'Gargaaraa Leenjisaa'],
        'Lab Technician' => ['የላቦራቶሪ ቴክኒሻን', 'Teknishaana Laaboraatoorii'],
        'Admin Staff' => ['የአስተዳደር ሠራተኛ', 'Hojjetaa Bulchiinsaa'],
        'Principal' => ['ርዕሰ መምህር', "Dura Bu'aa Mana Barumsaa"],
        'Vice Principal' => ['ምክትል ርዕሰ መምህር', "Itti Aanaa Dura Bu'aa Mana Barumsaa"],
        'Senior Teacher' => ['ከፍተኛ መምህር', 'Barsiisaa Olaanaa'],
        'Teacher' => ['መምህር', 'Barsiisaa'],
        'Assistant Teacher' => ['ረዳት መምህር', 'Gargaaraa Barsiisaa'],
        'Medical Director' => ['ሜዲካል ዳይሬክተር', 'Daarektara Yaalaa'],
        'General Practitioner' => ['ጠቅላላ ሐኪም', 'Hakiima Waliigalaa'],
        'Head Nurse' => ['ዋና ነርስ', 'Narsii Olaanaa'],
        'Nurse' => ['ነርስ', 'Narsii'],
        'Pharmacist' => ['ፋርማሲስት', 'Faarmaasistii'],
        'Receptionist' => ['እንግዳ ተቀባይ', 'Simataa Keessummaa'],
        'CEO' => ['ዋና ሥራ አስፈጻሚ (CEO)', 'Hojii Raawwachiisaa Olaanaa (CEO)'],
        'VP' => ['ምክትል ፕሬዚዳንት', 'Itti Aanaa Pirezidaantii'],
        'Director' => ['ዳይሬክተር', 'Daarektara'],
        'Manager' => ['ሥራ አስኪያጅ', 'Hoogganaa'],
        'Senior Officer' => ['ከፍተኛ ኦፊሰር', 'Ofiisara Olaanaa'],
        'Underwriter' => ['አንደርራይተር', 'Ogeessa Waliigaltee Inshuraansii (Underwriter)'],
        'Claims Officer' => ['የካሳ ኦፊሰር', 'Ofiisara Beenyaa'],
        'Agent' => ['ወኪል', "Bakka Bu'aa"],
        'Clerk' => ['የመዝገብ ሠራተኛ', 'Hojjetaa Galmee'],
        'General Manager' => ['ዋና ሥራ አስኪያጅ', 'Hoogganaa Olaanaa'],
        'Branch Manager' => ['የቅርንጫፍ ሥራ አስኪያጅ', 'Hoogganaa Damee'],
        'Loan Officer' => ['የብድር ኦፊሰር', 'Ofiisara Liqii'],
        'Savings Officer' => ['የቁጠባ ኦፊሰር', 'Ofiisara Qusannoo'],
        'Credit Analyst' => ['የብድር ተንታኝ', 'Xiinxalaa Liqii'],
        'Cashier' => ['ገንዘብ ተቀባይ', 'Qarshii Fudhataa'],
        'Project Director' => ['የፕሮጀክት ዳይሬክተር', 'Daarektara Pirojektii'],
        'Project Manager' => ['የፕሮጀክት ሥራ አስኪያጅ', 'Hoogganaa Pirojektii'],
        'Site Engineer' => ['የሳይት መሐንዲስ', 'Injinara Saayitii'],
        'Foreman' => ['ፎርማን', "Too'ataa Hojii (Foormaanii)"],
        'Surveyor' => ['ቀያሽ', 'Safaraa Lafaa'],
        'Skilled Worker' => ['የሠለጠነ ሠራተኛ', 'Hojjetaa Ogummaa Qabu'],
        'Laborer' => ['የጉልበት ሠራተኛ', 'Hojjetaa Humnaa'],
        'Guard' => ['ጥበቃ', 'Eegduu'],
        'Farm Manager' => ['የእርሻ ሥራ አስኪያጅ', 'Hoogganaa Qonnaa'],
        'Agronomist' => ['አግሮኖሚስት', 'Agronoomistii'],
        'Field Supervisor' => ['የመስክ ተቆጣጣሪ', "Too'ataa Dirree"],
        'Machine Operator' => ['የማሽን ኦፕሬተር', 'Oppireetara Maashinii'],
        'Farm Worker' => ['የእርሻ ሠራተኛ', 'Hojjetaa Qonnaa'],
        'Storekeeper' => ['ንብረት ጠባቂ', 'Eegduu Kuusaa'],
        'Head Chef' => ['ዋና ሼፍ', 'Sheefii Olaanaa'],
        'Sous Chef' => ['ምክትል ሼፍ', 'Itti Aanaa Sheefii'],
        'Cook' => ['ምግብ አብሳይ', 'Nyaata Bilcheessaa'],
        'Kitchen Assistant' => ['የወጥ ቤት ረዳት', 'Gargaaraa Kushiinaa'],
        'Waiter' => ['አስተናጋጅ', 'Keessummeessaa'],
        'Steward' => ['ስቲዋርድ', 'Hojjetaa Qulqullina Kushiinaa (Steward)'],
        'Store Manager' => ['የሱቅ ሥራ አስኪያጅ', 'Hoogganaa Suuqii'],
        'Assistant Manager' => ['ረዳት ሥራ አስኪያጅ', 'Gargaaraa Hoogganaa'],
        'Supervisor' => ['ተቆጣጣሪ', "Too'ataa"],
        'Sales Associate' => ['የሽያጭ ባለሙያ', 'Ogeessa Gurgurtaa'],
        'Stock Clerk' => ['የዕቃ ክምችት ሠራተኛ', 'Hojjetaa Kuusaa Meeshaalee'],
        'Warehouse Manager' => ['የመጋዘን ሥራ አስኪያጅ', 'Hoogganaa Mana Kuusaa'],
        'Sales Manager' => ['የሽያጭ ሥራ አስኪያጅ', 'Hoogganaa Gurgurtaa'],
        'Sales Representative' => ['የሽያጭ ተወካይ', "Bakka Bu'aa Gurgurtaa"],
        'Driver' => ['ሹፌር', 'Konkolaachisaa'],
        'Loader' => ['ጫኝና አውራጅ', "Fe'aa fi Buusaa"],
        'Managing Partner' => ['ዋና አጋር', 'Paartinara Hoogganaa'],
        'Partner' => ['አጋር', 'Paartinara'],
        'Senior Manager' => ['ከፍተኛ ሥራ አስኪያጅ', 'Hoogganaa Sadarkaa Olaanaa'],
        'Senior Consultant' => ['ከፍተኛ አማካሪ', 'Gorsaa Olaanaa'],
        'Consultant' => ['አማካሪ', 'Gorsaa'],
        'Analyst' => ['ተንታኝ', 'Xiinxalaa'],
        'Administrative Officer' => ['የአስተዳደር ኦፊሰር', 'Ofiisara Bulchiinsaa'],
        'Operations Manager' => ['የኦፕሬሽን ሥራ አስኪያጅ', 'Hoogganaa Hojmaataa'],
        'Dispatcher' => ['የስምሪት ሠራተኛ', 'Bobbaasaa'],
        'Fleet Supervisor' => ['የተሽከርካሪ ስምሪት ተቆጣጣሪ', "Too'ataa Konkolaattotaa"],
        'Warehouse Clerk' => ['የመጋዘን መዝገብ ሠራተኛ', 'Hojjetaa Galmee Mana Kuusaa'],
        'Customs Officer' => ['የጉምሩክ ኦፊሰር', 'Ofiisara Gumurukaa'],
        'Conductor' => ['የተሳፋሪ ረዳት', 'Gargaaraa Konkolaataa'],
        'Mechanic' => ['መካኒክ', 'Makaanikii'],
        'Ticket Clerk' => ['የትኬት ሻጭ', 'Gurguraa Tikeetii'],
        'CTO' => ['ዋና የቴክኖሎጂ ኃላፊ (CTO)', 'Hoogganaa Teeknooloojii Olaanaa (CTO)'],
        'Network Engineer' => ['የኔትወርክ መሐንዲስ', 'Injinara Neetworkii'],
        'Field Technician' => ['የመስክ ቴክኒሻን', 'Teknishaana Dirree'],
        'Customer Service Rep' => ['የደንበኞች አገልግሎት ሠራተኛ', 'Hojjetaa Tajaajila Maamiltootaa'],
        'Sales Agent' => ['የሽያጭ ወኪል', 'Ejentii Gurgurtaa'],
        'Site Supervisor' => ['የሳይት ተቆጣጣሪ', "Too'ataa Saayitii"],
        'Shift Leader' => ['የፈረቃ መሪ', 'Geggeessaa Shiftii'],
        'Security Guard' => ['የጥበቃ ሠራተኛ', 'Hojjetaa Eegumsaa'],
        'Cleaner' => ['የጽዳት ሠራተኛ', 'Hojjetaa Qulqullinaa'],
        'Control Room Operator' => ['የመቆጣጠሪያ ክፍል ኦፕሬተር', "Oppireetara Kutaa To'annoo"],
        'Center Manager' => ['የማዕከል ሥራ አስኪያጅ', 'Hoogganaa Giddugalaa'],
        'Team Leader' => ['የቡድን መሪ', 'Geggeessaa Garee'],
        'Quality Analyst' => ['የጥራት ተንታኝ', 'Xiinxalaa Qulqullinaa'],
        'Senior Agent' => ['ከፍተኛ ወኪል', "Bakka Bu'aa Olaanaa"],
        'Trainer' => ['አሠልጣኝ', 'Leenjisaa'],
        'IT Support' => ['የአይቲ ድጋፍ', 'Deeggarsa IT'],
        'Engineering Manager' => ['የምህንድስና ሥራ አስኪያጅ', 'Hoogganaa Injinariingii'],
        'Senior Engineer' => ['ከፍተኛ መሐንዲስ', 'Injinara Olaanaa'],
        'Software Engineer' => ['የሶፍትዌር መሐንዲስ', 'Injinara Sooftiweerii'],
        'Product Manager' => ['የፕሮዳክት ሥራ አስኪያጅ', 'Hoogganaa Oomishaa (Product)'],
        'Designer' => ['ዲዛይነር', 'Dizaayinara'],
        'QA Engineer' => ['የጥራት ማረጋገጫ መሐንዲስ', 'Injinara Mirkaneessa Qulqullinaa'],
        'Officer' => ['ኦፊሰር', 'Ofiisara'],
        'Accountant' => ['የሂሳብ ባለሙያ', 'Ogeessa Herregaa'],
        'Sales Staff' => ['የሽያጭ ሠራተኛ', 'Hojjetaa Gurgurtaa'],
        'Support Staff' => ['የድጋፍ ሰጪ ሠራተኛ', 'Hojjetaa Deeggarsaa'],
        'Regional Manager' => ['የክልል ሥራ አስኪያጅ', 'Hoogganaa Naannoo'],
        'Assistant Branch Manager' => ['ረዳት የቅርንጫፍ ሥራ አስኪያጅ', 'Gargaaraa Hoogganaa Damee'],
        'Head of Office' => ['የጽሕፈት ቤት ኃላፊ', 'Hoogganaa Waajjiraa'],
        'Deputy Head' => ['ምክትል ኃላፊ', 'Itti Aanaa Hoogganaa'],
        'Senior Expert' => ['ከፍተኛ ባለሙያ', 'Ogeessa Olaanaa'],
        'Expert' => ['ባለሙያ', 'Ogeessa'],
        'Junior Expert' => ['ጀማሪ ባለሙያ', 'Ogeessa Jalqabaa'],
        'Secretary' => ['ጸሐፊ', 'Barreessaa'],
        'Messenger' => ['ተላላኪ', 'Ergamaa'],
        'Deputy Director' => ['ምክትል ዳይሬክተር', 'Itti Aanaa Daarektaraa'],
        'President' => ['ፕሬዚዳንት', 'Pirezidaantii'],
        'Junior Officer' => ['ጀማሪ ኦፊሰር', 'Ofiisara Jalqabaa'],
        'Specialist' => ['ስፔሻሊስት ሐኪም', 'Hakiima Ispeeshaalistii'],
        'Plant Manager' => ['የፋብሪካ ሥራ አስኪያጅ', 'Hoogganaa Warshaa'],
        'Production Supervisor' => ['የምርት ተቆጣጣሪ', "Too'ataa Oomishaa"],
        'Quality Inspector' => ['የጥራት ተቆጣጣሪ', "Too'ataa Qulqullinaa"],
        'Technician' => ['ቴክኒሻን', 'Teknishaana'],
        'Worker' => ['ሠራተኛ', 'Hojjetaa'],
        'Country Director' => ['የአገር ዳይሬክተር', 'Daarektara Biyyaa'],
        'Program Manager' => ['የፕሮግራም ሥራ አስኪያጅ', 'Hoogganaa Sagantaa'],
        'Project Coordinator' => ['የፕሮጀክት አስተባባሪ', 'Qindeessaa Pirojektii'],
        'M&E Officer' => ['የክትትልና ግምገማ ኦፊሰር', 'Ofiisara Hordoffii fi Gamaaggamaa'],
        'Finance Officer' => ['የፋይናንስ ኦፊሰር', 'Ofiisara Faayinaansii'],
        'Field Officer' => ['የመስክ ኦፊሰር', 'Ofiisara Dirree'],
        'Housekeeper' => ['የቤት አያያዝ ሠራተኛ', 'Hojjetaa Qabiinsa Manaa'],
        'Chef' => ['ሼፍ', 'Sheefii'],
        'Professor' => ['ፕሮፌሰር', 'Piroofeesara'],
        'Lecturer' => ['ሌክቸረር', 'Barsiisaa Yuunivarsiitii'],
        'Lab Assistant' => ['የላቦራቶሪ ረዳት', 'Gargaaraa Laaboraatoorii'],
        'Senior Staff' => ['ከፍተኛ ሠራተኛ', 'Hojjetaa Olaanaa'],
        'Staff' => ['ሠራተኛ', 'Hojjetaa'],
        'Junior Staff' => ['ጀማሪ ሠራተኛ', 'Hojjetaa Jalqabaa'],
        'Intern' => ['ተለማማጅ', 'Shaakalaa'],
    ];

    /** The word a grade's name begins with: "Grade IV" reads ደረጃ IV and Sadarkaa IV. */
    private const GRADE = ['Grade', 'ደረጃ', 'Sadarkaa'];

    /** A proposed department, with the names the glossary has for it. */
    public function department(mixed $entry): mixed
    {
        return $this->named($entry, 'name', self::DEPARTMENTS);
    }

    /** A proposed position, with the titles the glossary has for it. */
    public function position(mixed $entry): mixed
    {
        return $this->named($entry, 'title', self::POSITIONS);
    }

    /**
     * A proposed grade. The ladder's names are a word and a number, so the
     * word is translated and the number kept.
     */
    public function grade(mixed $entry): mixed
    {
        $name = is_array($entry) ? ($entry['name'] ?? null) : $entry;

        if (! is_string($name) || ! str_starts_with($name, self::GRADE[0].' ')) {
            return $entry;
        }

        $number = substr($name, strlen(self::GRADE[0]));
        $named = is_array($entry) ? $entry : ['name' => $name];

        return $named + ['name_am' => self::GRADE[1].$number, 'name_om' => self::GRADE[2].$number];
    }

    /** @return list<string> */
    public function departmentNames(): array
    {
        return array_keys(self::DEPARTMENTS);
    }

    /** @return list<string> */
    public function positionNames(): array
    {
        return array_keys(self::POSITIONS);
    }

    /**
     * A bare name becomes a record; a record keeps what it already says, so a
     * template that names its own Amharic is not overwritten. Anything else
     * is handed back untouched for the provisioner to refuse.
     *
     * @param  array<string, array{0: string, 1: string}>  $glossary
     */
    private function named(mixed $entry, string $field, array $glossary): mixed
    {
        if (is_string($entry)) {
            $entry = [$field => $entry];
        }

        if (! is_array($entry)) {
            return $entry;
        }

        // The translations sit beside the key the name was written under, so
        // a template's `{name: ...}` position reads `name_am`, not `title_am`.
        foreach ([$field, 'name', 'title'] as $key) {
            $name = $entry[$key] ?? null;

            if (is_string($name) && isset($glossary[trim($name)])) {
                [$amharic, $oromo] = $glossary[trim($name)];

                return $entry + [$key.'_am' => $amharic, $key.'_om' => $oromo];
            }
        }

        return $entry;
    }
}
