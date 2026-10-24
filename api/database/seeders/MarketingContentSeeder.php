<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Services\FileStorageService;
use App\Support\MarketingImage;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * The landing page's first-run content, in English and Amharic.
 *
 * Every sentence here was on the public site as a translation key, checked
 * against the code by the capability audit of 2026-10-17, or was written
 * for the problems section inside the same allowed claims
 * (App\Support\MarketingClaims). MarketingSeedCopyTest runs the claim rule
 * and the house style over all of it.
 *
 * firstOrCreate, never updateOrCreate: every column here is something the
 * platform operator edits at /admin/marketing, and `db:seed` is what runs
 * after a restore. A re-seed establishes rows that are missing and leaves
 * every existing one alone, text and picture alike. Pictures come from the
 * manifest beside this file, see loadImages().
 */
class MarketingContentSeeder extends Seeder
{
    public const ASSETS_DIR = __DIR__.'/assets/marketing';

    public function run(): void
    {
        $order = 1;
        foreach ($this->sections() as $kind => $section) {
            $items = $section['items'] ?? [];
            unset($section['items']);

            MarketingSection::query()->firstOrCreate(['kind' => $kind], [...$section, 'sort_order' => $order++]);

            $position = 1;
            foreach ($items as $slug => $item) {
                MarketingItem::query()->firstOrCreate(
                    ['section_kind' => $kind, 'slug' => $slug],
                    [...$item, 'sort_order' => $position++],
                );
            }
        }

        $this->loadImages();
    }

    /**
     * Attach the seeded illustrations listed in `assets/marketing/manifest.json`
     * to rows that have no picture yet. A missing file is skipped with a
     * warning: the page draws its own scene for that row. An existing picture
     * is never replaced, so an operator's upload survives a re-seed.
     */
    private function loadImages(): void
    {
        $manifestPath = $this->assetsDir().'/manifest.json';
        if (! is_file($manifestPath)) {
            return;
        }

        /** @var array{images?: list<array<string, mixed>>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $storage = app(FileStorageService::class);

        foreach ($manifest['images'] ?? [] as $entry) {
            $kind = (string) ($entry['kind'] ?? '');
            $slug = (string) ($entry['slug'] ?? '');
            $file = $this->assetsDir().'/'.basename((string) ($entry['file'] ?? ''));

            $owner = $kind === 'hero'
                ? MarketingSection::query()->where('kind', 'hero')->first()
                : MarketingItem::query()->where('section_kind', $kind)->where('slug', $slug)->first();

            if ($owner === null) {
                $this->warn("marketing manifest: no row for {$kind}/{$slug}, skipped.");

                continue;
            }
            if ($owner->hasImage()) {
                continue;
            }
            if (! is_file($file)) {
                $this->warn("marketing manifest: {$kind}/{$slug} names a file that is not there, skipped.");

                continue;
            }

            // `test` mode skips PHP's is_uploaded_file() check, which only holds
            // for a file that arrived in an HTTP request.
            $upload = new UploadedFile($file, basename($file), mime_content_type($file) ?: 'image/jpeg', null, true);
            $stored = $storage->uploadMarketingImage($upload, $owner->public_id);

            $owner->forceFill([
                ...MarketingImage::columns($stored),
                'image_alt' => isset($entry['alt']) ? (string) $entry['alt'] : null,
                'image_alt_am' => isset($entry['alt_am']) ? (string) $entry['alt_am'] : null,
            ])->save();
        }
    }

    /**
     * Production seeds through the maintenance endpoint, where there is no
     * console to print to, so a skipped picture is logged rather than printed.
     */
    private function warn(string $message): void
    {
        Log::warning($message);
    }

    /** Where the manifest and its files live; a test points this elsewhere. */
    protected function assetsDir(): string
    {
        return self::ASSETS_DIR;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sections(): array
    {
        return [
            'hero' => [
                'heading' => 'Attendance, payroll and HR, built for Ethiopian organisations',
                'heading_am' => 'ለኢትዮጵያ ድርጅቶች የተሠራ የመገኘት፣ የደመወዝና የሰው ሀብት መድረክ',
                'subheading' => 'Employees check in from a phone, a kiosk or the web, even without a network. Payroll follows Proclamation 1395/2025 and the Ethiopian calendar. Everything is in Amharic and English.',
                'subheading_am' => 'ሠራተኞች ከስልክ፣ ከኪዮስክ ወይም ከድር ይገባሉ፤ አውታረ መረብ ባይኖርም። ደመወዝ አዋጅ 1395/2025ንና የኢትዮጵያን የቀን አቆጣጠር ይከተላል። ሁሉም በአማርኛና በእንግሊዝኛ ነው።',
            ],

            'problems' => [
                'heading' => 'The work that fills an HR office, and what ETHR does with it',
                'heading_am' => 'የሰው ሀብት ቢሮን የሚሞላው ሥራ፣ እና ETHR በእሱ ላይ የሚያደርገው',
                'subheading' => 'Six things that go wrong today, each beside the way ETHR handles it.',
                'subheading_am' => 'ዛሬ የሚበላሹ ስድስት ነገሮች፣ እያንዳንዳቸው ETHR ከሚይዛቸው መንገድ ጎን።',
                'items' => [
                    'paper_attendance' => [
                        'icon' => 'clipboard-list',
                        'title' => 'Attendance on paper',
                        'title_am' => 'በወረቀት ላይ መገኘት',
                        'body' => 'A shift register is signed late, overtime is argued about at month end, and nobody can say who was on site at 8:02.',
                        'body_am' => 'የፈረቃ መዝገብ ዘግይቶ ይፈረማል፣ የትርፍ ሰዓት ክፍያ በወር መጨረሻ ይከራከርበታል፣ በ8:02 ማን በቦታው እንደነበር ማንም አይናገርም።',
                        'detail' => 'Staff check in from a phone, a shared kiosk, a QR code or the web, with the time and place recorded as it happens. Overtime is counted from the record, not from memory.',
                        'detail_am' => 'ሠራተኞች ከስልክ፣ ከጋራ ኪዮስክ፣ በQR ኮድ ወይም ከድር ይገባሉ፤ ሰዓቱና ቦታው ወዲያው ይመዘገባል። የትርፍ ሰዓት ከመዝገቡ ይቆጠራል እንጂ ከትውስታ አይደለም።',
                        'link_path' => '/attendance-system',
                    ],
                    'manual_payroll' => [
                        'icon' => 'calculator',
                        'title' => 'Payroll recomputed by hand',
                        'title_am' => 'በእጅ የሚሰላ ደመወዝ',
                        'body' => 'Every month someone works the income tax ladder and the pension split in a spreadsheet, and one wrong cell reaches a payslip.',
                        'body_am' => 'በየወሩ አንድ ሰው የገቢ ግብር ደረጃዎችንና የጡረታ ክፍፍልን በስፕሬድሺት ይሠራል፤ አንድ የተሳሳተ ሕዋስ የደመወዝ ወረቀት ላይ ይደርሳል።',
                        'detail' => 'Income tax under Proclamation 1395/2025, pension and Pagume proration are applied automatically, with a calculation trail on every payslip and an approval before anyone sees it.',
                        'detail_am' => 'በአዋጅ 1395/2025 መሠረት የገቢ ግብር፣ ጡረታና የጳጉሜን ተመጣጣኝ ክፍያ በራስ ሰር ይተገበራሉ፤ በእያንዳንዱ የደመወዝ ወረቀት ላይ የስሌት ዱካ አለ፣ ማንም ከማየቱ በፊትም ይጸድቃል።',
                        'link_path' => '/payroll-software',
                    ],
                    'leave_spreadsheets' => [
                        'icon' => 'table',
                        'title' => 'Leave balances in spreadsheets',
                        'title_am' => 'በስፕሬድሺት ውስጥ የፈቃድ ቀሪ',
                        'body' => 'Balances live in a file only one person can open. A request waits for that person, and the Ethiopian calendar is counted by hand.',
                        'body_am' => 'ቀሪዎቹ አንድ ሰው ብቻ በሚከፍተው ፋይል ውስጥ ናቸው። ጥያቄ ያንን ሰው ይጠብቃል፤ የኢትዮጵያ የቀን አቆጣጠርም በእጅ ይቆጠራል።',
                        'detail' => 'Employees see their own balance and request leave from a phone. Managers decide from one inbox, and the days are counted on the Ethiopian calendar, Pagume and holidays included.',
                        'detail_am' => 'ሠራተኞች የራሳቸውን ቀሪ ያያሉ፣ ፈቃድን ከስልክ ይጠይቃሉ። ኃላፊዎች ከአንድ ሳጥን ይወስናሉ፤ ቀናቱ ጳጉሜንና በዓላትን ጨምሮ በኢትዮጵያ የቀን አቆጣጠር ይቆጠራሉ።',
                        'link_path' => '/leave-management',
                    ],
                    'branches_offline' => [
                        'icon' => 'signal-zero',
                        'title' => 'Branches without a connection',
                        'title_am' => 'ግንኙነት የሌላቸው ቅርንጫፎች',
                        'body' => 'A regional branch loses the network for an afternoon, and its records for the day go with it.',
                        'body_am' => 'አንድ የክልል ቅርንጫፍ ለአንድ ከሰዓት አውታረ መረብ ያጣል፤ ከእሱም ጋር የዕለቱን መዝገቦች።',
                        'detail' => 'Check-ins queue on the device and upload themselves the moment the connection returns. Nothing is typed twice.',
                        'detail_am' => 'መግቢያዎች በመሣሪያው ላይ ይሰለፋሉ፣ ግንኙነቱ እንደተመለሰ ራሳቸው ይላካሉ። ምንም ሁለት ጊዜ አይተየብም።',
                        'link_path' => null,
                    ],
                    'bilingual_staff' => [
                        'icon' => 'languages',
                        'title' => 'One system, two languages',
                        'title_am' => 'አንድ ሥርዓት፣ ሁለት ቋንቋ',
                        'body' => 'The finance team works in English, the floor supervisors in Amharic, and a system built for one of them leaves the other guessing.',
                        'body_am' => 'የፋይናንስ ቡድኑ በእንግሊዝኛ፣ የወለል ተቆጣጣሪዎች በአማርኛ ይሠራሉ፤ ለአንዱ ብቻ የተሠራ ሥርዓት ሌላውን በግምት ይተዋል።',
                        'detail' => 'Every screen, payslip and notification is in Amharic and English, with the Ethiopian calendar beside the Gregorian one. Each person chooses their own language.',
                        'detail_am' => 'እያንዳንዱ ገጽ፣ የደመወዝ ወረቀትና ማሳወቂያ በአማርኛና በእንግሊዝኛ ነው፤ የኢትዮጵያ የቀን አቆጣጠር ከጎርጎሪዮሱ ጎን። እያንዳንዱ ሰው የራሱን ቋንቋ ይመርጣል።',
                        'link_path' => '/ethiopian-calendar-hr',
                    ],
                    'contracts_expiring' => [
                        'icon' => 'file-clock',
                        'title' => 'Contracts that expire unnoticed',
                        'title_am' => 'ሳይታወቅ የሚያልቁ ውሎች',
                        'body' => 'A fixed-term contract ends, nobody is told, and the renewal is signed weeks late.',
                        'body_am' => 'የተወሰነ ጊዜ ውል ያበቃል፣ ማንም አይነገረውም፣ እድሳቱም ከሳምንታት በኋላ ይፈረማል።',
                        'detail' => 'Every employee has a complete digital file, with contracts and documents, and the reminders arrive before an expiry date, not after it.',
                        'detail_am' => 'እያንዳንዱ ሠራተኛ ውሎችንና ሰነዶችን የያዘ ሙሉ ዲጂታል ፋይል አለው፤ ማስታወሻዎቹ ከማብቂያ ቀን በፊት ይደርሳሉ እንጂ በኋላ አይደለም።',
                        'link_path' => null,
                    ],
                ],
            ],

            'capabilities' => [
                'heading' => 'What ETHR does for your organisation',
                'heading_am' => 'ETHR ለድርጅትዎ የሚሠራው',
                'subheading' => 'Five capabilities, each paired with the work it takes off your HR team.',
                'subheading_am' => 'አምስት አቅሞች፤ እያንዳንዳቸው ከሰው ሀብት ቡድንዎ ከሚያቃልሉት ሥራ ጋር።',
                'items' => [
                    'records' => [
                        'icon' => 'users',
                        'title' => 'Employee records and organisational structure',
                        'title_am' => 'የሠራተኛ መዝገብና የድርጅት መዋቅር',
                        'body' => 'A complete digital file for every employee, with contracts, documents and expiry reminders. Tax IDs and bank details are encrypted, and your departments, branches, positions and grades form an org chart.',
                        'body_am' => 'ለእያንዳንዱ ሠራተኛ ሙሉ ዲጂታል ፋይል፤ ውሎች፣ ሰነዶችና የማብቂያ ማስታወሻዎችን ጨምሮ። የግብር መለያ ቁጥርና የባንክ መረጃ ተመስጥረው ይቀመጣሉ፤ መምሪያዎች፣ ቅርንጫፎች፣ የሥራ መደቦችና ደረጃዎች የድርጅት ሠንጠረዥ ይሆናሉ።',
                        'detail' => 'Establishes reliable workforce data',
                        'detail_am' => 'አስተማማኝ የሠራተኛ መረጃ ይገነባል',
                        'link_path' => null,
                    ],
                    'time' => [
                        'icon' => 'clock',
                        'title' => 'Attendance, shifts and leave',
                        'title_am' => 'ሰዓት መቆጣጠሪያ፣ ፈረቃና ፈቃድ',
                        'body' => 'Clock in from the web, a phone, a shared kiosk or a QR code, even without internet. Rotating and night shifts, and leave that follows the Ethiopian calendar, Pagume included.',
                        'body_am' => 'ከድር፣ ከስልክ፣ ከጋራ ኪዮስክ ወይም በQR ኮድ ይግቡ፤ ኢንተርኔት ባይኖርም። የሚዞሩና የሌሊት ፈረቃዎች፣ እንዲሁም ጳጉሜንን ጨምሮ የኢትዮጵያን የቀን አቆጣጠር የሚከተል ፈቃድ።',
                        'detail' => 'Reduces repetitive administration',
                        'detail_am' => 'ተደጋጋሚ የአስተዳደር ሥራን ይቀንሳል',
                        'link_path' => '/attendance-system',
                    ],
                    'payroll' => [
                        'icon' => 'wallet',
                        'title' => 'Payroll and payslips',
                        'title_am' => 'ደመወዝና የደመወዝ ወረቀት',
                        'body' => 'Ethiopian income tax under Proclamation 1395/2025, pension, overtime and Pagume proration, with a calculation trail for every payslip and approval before employees see it.',
                        'body_am' => 'በአዋጅ ቁጥር 1395/2025 መሠረት የኢትዮጵያ የገቢ ግብር፣ ጡረታ፣ የትርፍ ሰዓት ክፍያና የጳጉሜን ተመጣጣኝ ክፍያ፤ ለእያንዳንዱ የደመወዝ ወረቀት የስሌት ዱካ፣ ሠራተኞች ከማየታቸው በፊትም ይጸድቃል።',
                        'detail' => 'Brings care to a high-impact, sensitive workflow',
                        'detail_am' => 'ከፍተኛ ተጽእኖ ላለው ስሱ ሥራ ጥንቃቄ ያመጣል',
                        'link_path' => '/payroll-software',
                    ],
                    'self_service' => [
                        'icon' => 'smartphone',
                        'title' => 'Employee self-service and approvals',
                        'title_am' => 'የሠራተኛ ራስ አገልግሎትና ማጽደቂያ',
                        'body' => 'Staff check their shift, request leave, download payslips and update their details from any phone, in Amharic or English. Managers decide requests from one approvals inbox.',
                        'body_am' => 'ሠራተኞች ፈረቃቸውን ያያሉ፣ ፈቃድ ይጠይቃሉ፣ የደመወዝ ወረቀት ያወርዳሉ፣ መረጃቸውንም ከማንኛውም ስልክ በአማርኛ ወይም በእንግሊዝኛ ያዘምናሉ። ኃላፊዎች ጥያቄዎችን ከአንድ የማጽደቂያ ሳጥን ይወስናሉ።',
                        'detail' => 'Reduces routine HR requests',
                        'detail_am' => 'የተለመዱ የሰው ሀብት ጥያቄዎችን ይቀንሳል',
                        'link_path' => '/leave-management',
                    ],
                    'analytics' => [
                        'icon' => 'bar-chart-3',
                        'title' => 'Workforce analytics',
                        'title_am' => 'የሠራተኛ ኃይል ትንተና',
                        'body' => 'Headcount, attendance, payroll cost and overtime on one dashboard, with department and branch comparisons, scheduled reports and alerts when a figure crosses your threshold.',
                        'body_am' => 'የሠራተኛ ብዛት፣ የሥራ መገኘት፣ የደመወዝ ወጪና የትርፍ ሰዓት በአንድ ዳሽቦርድ፤ የመምሪያና የቅርንጫፍ ንጽጽር፣ የታቀዱ ሪፖርቶች፣ አንድ አሃዝ ያስቀመጡትን ወሰን ሲያልፍም ማሳወቂያ።',
                        'detail' => 'Supports better management decisions',
                        'detail_am' => 'የተሻለ የአስተዳደር ውሳኔን ይደግፋል',
                        'link_path' => null,
                    ],
                ],
            ],

            'how_it_works' => [
                'heading' => 'Up and running in minutes',
                'heading_am' => 'በደቂቃዎች ውስጥ ይጀምሩ',
                'subheading' => null,
                'subheading_am' => null,
                'items' => [
                    'register' => [
                        'title' => 'Create your account',
                        'title_am' => 'መለያ ይፍጠሩ',
                        'body' => 'Sign up in two minutes. Add your organisation details and invite your team.',
                        'body_am' => 'በሁለት ደቂቃ ውስጥ ይመዝገቡ። የድርጅትዎን ዝርዝር ያስገቡ እና ቡድንዎን ይጋብዙ።',
                    ],
                    'configure' => [
                        'title' => 'Configure your workspace',
                        'title_am' => 'ቦታዎን ያዋቅሩ',
                        'body' => 'Set up departments, shifts, leave policies and payroll rules for your organisation, or start from a template for your sector.',
                        'body_am' => 'ክፍሎችን፣ ፈረቃዎችን፣ የፈቃድ ፖሊሲዎችን እና የደመወዝ ደንቦችን ያዘጋጁ፣ ወይም ለዘርፍዎ ከተዘጋጀ ቅጥ ይጀምሩ።',
                    ],
                    'go_live' => [
                        'title' => 'Go live',
                        'title_am' => 'ያስጀምሩ',
                        'body' => 'Start tracking attendance, processing payroll and managing your workforce.',
                        'body_am' => 'መገኘት መከታተል፣ ደመወዝ ማስኬድ እና ሠራተኞችን ማስተዳደር ይጀምሩ።',
                    ],
                ],
            ],

            'industries' => [
                'heading' => 'Built for Ethiopian industries',
                'heading_am' => 'ለኢትዮጵያ ኢንዱስትሪዎች የተዘጋጀ',
                'subheading' => 'Pre-configured templates for your sector',
                'subheading_am' => 'ለዘርፍዎ አስቀድሞ የተዋቀሩ ቅጦች',
                'items' => [
                    'government' => ['icon' => 'landmark', 'title' => 'Government', 'title_am' => 'መንግሥት'],
                    'banking' => ['icon' => 'landmark', 'title' => 'Banking', 'title_am' => 'ባንክ'],
                    'healthcare' => ['icon' => 'hospital', 'title' => 'Healthcare', 'title_am' => 'ጤና'],
                    'manufacturing' => ['icon' => 'factory', 'title' => 'Manufacturing', 'title_am' => 'ማኑፋክቸሪንግ'],
                    'ngo' => ['icon' => 'heart', 'title' => 'NGO', 'title_am' => 'መንግሥታዊ ያልሆኑ ድርጅቶች'],
                    'hospitality' => ['icon' => 'hotel', 'title' => 'Hospitality', 'title_am' => 'እንግዳ ተቀባይነት'],
                    'education' => ['icon' => 'graduation-cap', 'title' => 'Education', 'title_am' => 'ትምህርት'],
                    'general' => ['icon' => 'briefcase', 'title' => 'General', 'title_am' => 'ጠቅላላ'],
                ],
            ],

            'trust' => [
                'heading' => 'Your data, stored in Ethiopia',
                'heading_am' => 'ውሂብዎ፣ በኢትዮጵያ ውስጥ',
                'subheading' => 'ETHR keeps your organisation\'s data on servers in Ethiopia, on Ethio Telecom hosting or your own, as the Personal Data Protection Proclamation No. 1321/2024 requires. Your organisation stays in control of its data; ETHR supplies the safeguards below.',
                'subheading_am' => 'ኢቲኤችአር የድርጅትዎን ውሂብ በኢትዮጵያ ውስጥ ባሉ ሰርቨሮች ላይ ያስቀምጣል፤ በኢትዮ ቴሌኮም ማስተናገጃ ወይም በራስዎ ሰርቨር፣ የግል መረጃ ጥበቃ አዋጅ ቁጥር 1321/2024 እንደሚጠይቀው። ድርጅትዎ የውሂቡ ተቆጣጣሪ ሆኖ ይቀጥላል፤ ኢቲኤችአር ከታች ያሉትን ጥበቃዎች ያቀርባል።',
                'items' => [
                    'data_residency' => [
                        'icon' => 'map-pin',
                        'title' => 'Data residency',
                        'title_am' => 'የውሂብ መኖሪያ',
                        'body' => 'Stored on servers in Ethiopia, as Article 22 of Proclamation 1321/2024 requires for personal data collected here.',
                        'body_am' => 'በአዋጅ 1321/2024 አንቀጽ 22 መሠረት፣ እዚህ የተሰበሰበ የግል መረጃ በኢትዮጵያ ውስጥ ባሉ ሰርቨሮች ላይ ይቀመጣል።',
                    ],
                    'encryption' => [
                        'icon' => 'lock',
                        'title' => 'Encryption at rest',
                        'title_am' => 'በእረፍት ጊዜ ምስጠራ',
                        'body' => 'Bank account, TIN and national ID numbers are encrypted at rest with AES-256.',
                        'body_am' => 'የባንክ ሂሳብ፣ የግብር መለያ (ቲን) እና የብሔራዊ መታወቂያ ቁጥሮች በAES-256 ተመስጥረው ይቀመጣሉ።',
                    ],
                    'isolation' => [
                        'icon' => 'shield',
                        'title' => 'Tenant isolation',
                        'title_am' => 'የተጠቃሚ ማግለል',
                        'body' => 'Every query is scoped to one organization by a guard that fails closed: with no organization resolved, it returns no rows rather than all of them.',
                        'body_am' => 'እያንዳንዱ ጥያቄ በአንድ ድርጅት ብቻ ይገደባል፤ ድርጅቱ ካልታወቀ ጥያቄው ሁሉንም ሳይሆን ምንም አይመልስም።',
                    ],
                    'audit' => [
                        'icon' => 'scroll-text',
                        'title' => 'Audit trail',
                        'title_am' => 'የኦዲት ዱካ',
                        'body' => 'Sensitive actions are recorded in an audit trail that cannot be edited or deleted.',
                        'body_am' => 'ሚስጥራዊ ተግባራት ሊስተካከል ወይም ሊሰረዝ በማይችል የኦዲት ዱካ ይመዘገባሉ።',
                    ],
                ],
            ],

            'cta' => [
                'heading' => 'Start your 6-month free trial',
                'heading_am' => 'የ6 ወር ነጻ ሙከራ ይጀምሩ',
                'subheading' => 'No credit card required. Full access to all features.',
                'subheading_am' => 'ክሬዲት ካርድ አያስፈልግም። ሁሉም ባህሪያት ሙሉ ተደራሽነት።',
                'items' => [
                    'trial' => ['title' => '6-month free trial', 'title_am' => '6-ወር ነፃ ሙከራ'],
                    'no_card' => ['title' => 'No credit card', 'title_am' => 'ክሬዲት ካርድ አያስፈልግም'],
                    'cancel' => ['title' => 'Cancel anytime', 'title_am' => 'በማንኛውም ጊዜ ይሰርዙ'],
                ],
            ],
        ];
    }
}
