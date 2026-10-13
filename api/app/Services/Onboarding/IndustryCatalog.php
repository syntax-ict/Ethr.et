<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * The selectable industries, each an alias onto one of the eight maintained
 * organization templates plus an optional thin override patch.
 *
 * See ONBOARDING_V2.md decision D3: adding an industry is adding a row here, not
 * authoring a ninth template. `base` names the OrganizationTemplate slug the
 * industry inherits; `overrides` is a partial `template_data` merged on top by
 * IndustryProfileResolver (list sections replace, `settings` deep-merges).
 *
 * An industry is the *archetype* — what the organisation is. `profile` names
 * its usual WorkforceProfileCatalog entry (how its people work) and `policy`
 * its PolicyProfileCatalog entry (which employment law its policies start
 * from). Both are defaults the admin can change in the review step, which is
 * what lets one hospital row serve a 24/7 hospital and an office-hours clinic.
 *
 * Labels carry an Amharic variant inline, the same way OrganizationTemplate
 * stores its display name, rather than through translation keys.
 */
final class IndustryCatalog
{
    /**
     * Bumped when an industry's or profile's proposed configuration changes, and
     * recorded on the tenant when a plan is applied. Re-applying a newer
     * catalogue is create-only like any other apply: it adds what is missing
     * and never rewrites what the tenant has.
     */
    public const VERSION = '2026.10.1';

    /** Civil-service positions shared by the government tiers. */
    private const CIVIL_SERVICE_POSITIONS = ['Head of Office', 'Deputy Head', 'Director', 'Team Leader', 'Senior Expert', 'Expert', 'Junior Expert', 'Officer', 'Secretary', 'Driver', 'Messenger', 'Guard'];

    /**
     * @var array<string, array{
     *     label: string,
     *     label_am: string,
     *     base: string,
     *     icon: string,
     *     group: string,
     *     profile: string,
     *     policy: string,
     *     overrides?: array<string, mixed>
     * }>
     */
    private const INDUSTRIES = [
        // ── Public sector → government ──────────────────────────────
        'federal_government' => [
            'label' => 'Federal Government', 'label_am' => 'የፌዴራል መንግስት',
            'base' => 'government', 'icon' => 'landmark', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
        ],
        'ministry' => [
            'label' => 'Federal Ministry', 'label_am' => 'ሚኒስቴር',
            'base' => 'government', 'icon' => 'building-government', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Minister's Office", "State Ministers' Offices", 'Policy & Planning', 'Finance & Procurement', 'Human Resource Management', 'Legal Services', 'Internal Audit', 'ICT', 'Ethics & Anti-Corruption', 'Public Relations & Communication', 'Women & Social Inclusion'],
                'positions' => ['Minister', 'State Minister', 'Chief Executive Officer', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'MIN-{SEQ:4}'],
            ],
        ],
        'federal_agency' => [
            'label' => 'Federal Agency / Authority', 'label_am' => 'የፌዴራል ኤጀንሲ / ባለሥልጣን',
            'base' => 'government', 'icon' => 'landmark', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Director General's Office", 'Operations Directorate', 'Planning, Monitoring & Evaluation', 'Finance & Procurement', 'Human Resource Management', 'Legal Services', 'Internal Audit', 'ICT', 'Ethics & Anti-Corruption', 'Public Relations'],
                'positions' => ['Director General', 'Deputy Director General', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'AGY-{SEQ:4}'],
            ],
        ],
        'regional_government' => [
            'label' => 'Regional Government', 'label_am' => 'የክልል መንግስት',
            'base' => 'government', 'icon' => 'map', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
        ],
        'regional_bureau' => [
            'label' => 'Regional Bureau', 'label_am' => 'የክልል ቢሮ',
            'base' => 'government', 'icon' => 'building-2', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Bureau Head's Office", 'Core Programme Directorate', 'Planning, Monitoring & Evaluation', 'Finance & Procurement', 'Human Resource Management', 'Legal Services', 'Internal Audit', 'ICT', 'Public Relations'],
                'positions' => ['Bureau Head', 'Deputy Bureau Head', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'BUR-{SEQ:4}'],
            ],
        ],
        'zonal_administration' => [
            'label' => 'Zonal Administration', 'label_am' => 'የዞን አስተዳደር',
            'base' => 'government', 'icon' => 'map', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Zone Administrator's Office", 'Finance Department', 'Plan & Development Department', 'Civil Service Department', 'Education Department', 'Health Department', 'Agriculture Department', 'Peace & Security Department', 'Women & Social Affairs Department'],
                'positions' => ['Zone Administrator', 'Deputy Administrator', 'Department Head', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'ZON-{SEQ:4}'],
            ],
        ],
        'city_administration' => [
            'label' => 'City Administration', 'label_am' => 'የከተማ አስተዳደር',
            'base' => 'government', 'icon' => 'building-2', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Mayor's Office", "City Manager's Office", 'Finance', 'Revenue', 'Land Development & Management', 'Urban Planning', 'Construction & Infrastructure', 'Trade', 'Civil Registration', 'Sanitation & Beautification', 'Peace & Security', 'Human Resource Management'],
                'positions' => ['Mayor', 'Deputy Mayor', 'City Manager', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'CITY-{SEQ:4}'],
            ],
        ],
        'woreda_administration' => [
            'label' => 'Woreda Administration', 'label_am' => 'የወረዳ አስተዳደር',
            'base' => 'government', 'icon' => 'map-pin', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Woreda Administrator's Office", 'Finance Office', 'Plan & Development Office', 'Civil Service Office', 'Education Office', 'Health Office', 'Agriculture Office', 'Women, Children & Youth Office', 'Trade & Market Development Office', 'Peace & Security Office', 'Land Administration Office', 'Revenue Office'],
                'positions' => ['Woreda Administrator', 'Deputy Administrator', 'Office Head', ...self::CIVIL_SERVICE_POSITIONS],
                'settings' => ['employee_number_format' => 'WOR-{SEQ:4}'],
            ],
        ],
        'sector_office' => [
            'label' => 'Government Sector Office', 'label_am' => 'የመንግሥት ዘርፍ ጽሕፈት ቤት',
            'base' => 'government', 'icon' => 'building', 'group' => 'public_sector',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ["Office Head's Office", 'Core Service Unit', 'Planning & Reporting', 'Finance & Procurement', 'Human Resource Management', 'Support Services'],
                'positions' => self::CIVIL_SERVICE_POSITIONS,
                'settings' => ['employee_number_format' => 'OFF-{SEQ:4}'],
            ],
        ],

        // ── Education → university ───────────────────────────────────
        'university' => [
            'label' => 'University', 'label_am' => 'ዩኒቨርሲቲ',
            'base' => 'university', 'icon' => 'graduation-cap', 'group' => 'education',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
        ],
        'tvet' => [
            'label' => 'TVET College', 'label_am' => 'ቴክኒክና ሙያ ኮሌጅ',
            'base' => 'university', 'icon' => 'wrench', 'group' => 'education',
            'profile' => 'standard_office', 'policy' => 'ethiopia_public',
            'overrides' => [
                'departments' => ['Academic Affairs', 'Workshops & Labs', 'Cooperative Training', 'Student Services', 'Registrar', 'Finance', 'HR', 'Facilities'],
                'positions' => ['Dean', 'Department Head', 'Senior Instructor', 'Instructor', 'Assistant Instructor', 'Lab Technician', 'Admin Staff'],
                'settings' => ['employee_number_format' => 'TVET-{SEQ:4}'],
            ],
        ],
        'school' => [
            'label' => 'School', 'label_am' => 'ትምህርት ቤት',
            'base' => 'university', 'icon' => 'school', 'group' => 'education',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Academics', 'Student Affairs', 'Administration', 'Finance', 'Library', 'Facilities'],
                'positions' => ['Principal', 'Vice Principal', 'Department Head', 'Senior Teacher', 'Teacher', 'Assistant Teacher', 'Admin Staff'],
                'settings' => ['employee_number_format' => 'SCH-{SEQ:4}'],
            ],
        ],

        // ── Healthcare → hospital ────────────────────────────────────
        'hospital' => [
            'label' => 'Hospital', 'label_am' => 'ሆስፒታል',
            'base' => 'hospital', 'icon' => 'building-hospital', 'group' => 'healthcare',
            'profile' => 'continuous_24_7', 'policy' => 'ethiopia_private',
            'overrides' => [
                'allowances' => [
                    ['name' => 'Hazard allowance', 'name_am' => 'የጤና ጠንቅ አበል'],
                    ['name' => 'Night duty allowance', 'name_am' => 'የሌሊት ተረኛ አበል'],
                ],
            ],
        ],
        'health_center' => [
            'label' => 'Health Center / Clinic', 'label_am' => 'ጤና ጣቢያ / ክሊኒክ',
            'base' => 'hospital', 'icon' => 'stethoscope', 'group' => 'healthcare',
            'profile' => 'extended_hours_two_shift', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Outpatient', 'Emergency', 'Pharmacy', 'Laboratory', 'Nursing', 'Administration'],
                'positions' => ['Medical Director', 'General Practitioner', 'Head Nurse', 'Nurse', 'Lab Technician', 'Pharmacist', 'Receptionist'],
                'allowances' => [
                    ['name' => 'Hazard allowance', 'name_am' => 'የጤና ጠንቅ አበል'],
                ],
                'settings' => ['employee_number_format' => 'HC-{SEQ:4}'],
            ],
        ],

        // ── Nonprofit → ngo ──────────────────────────────────────────
        'ngo' => [
            'label' => 'NGO / International Organization', 'label_am' => 'መንግስታዊ ያልሆነ ድርጅት',
            'base' => 'ngo', 'icon' => 'globe', 'group' => 'nonprofit',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
        ],

        // ── Financial → bank ─────────────────────────────────────────
        'bank' => [
            'label' => 'Bank', 'label_am' => 'ባንክ',
            'base' => 'bank', 'icon' => 'building-bank', 'group' => 'financial',
            'profile' => 'six_day_week', 'policy' => 'ethiopia_private',
        ],
        'insurance' => [
            'label' => 'Insurance Company', 'label_am' => 'የመድን ድርጅት',
            'base' => 'bank', 'icon' => 'shield', 'group' => 'financial',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Underwriting', 'Claims', 'Actuarial', 'Reinsurance', 'Sales & Marketing', 'Finance', 'Risk & Compliance', 'IT', 'HR'],
                'positions' => ['CEO', 'VP', 'Director', 'Manager', 'Senior Officer', 'Underwriter', 'Claims Officer', 'Agent', 'Clerk'],
                'settings' => ['employee_number_format' => 'INS-{SEQ:4}'],
            ],
        ],
        'microfinance' => [
            'label' => 'Microfinance Institution', 'label_am' => 'የአነስተኛ ብድር ተቋም',
            'base' => 'bank', 'icon' => 'coins', 'group' => 'financial',
            'profile' => 'six_day_week', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Loan Operations', 'Savings', 'Credit Appraisal', 'Collections', 'Branch Operations', 'Finance', 'Compliance', 'HR'],
                'positions' => ['General Manager', 'Branch Manager', 'Loan Officer', 'Savings Officer', 'Credit Analyst', 'Cashier', 'Clerk'],
                'settings' => ['employee_number_format' => 'MFI-{SEQ:4}'],
            ],
        ],

        // ── Industrial → manufacturing ───────────────────────────────
        'manufacturing' => [
            'label' => 'Manufacturing / Factory', 'label_am' => 'ማምረቻ / ፋብሪካ',
            'base' => 'manufacturing', 'icon' => 'building-factory', 'group' => 'industrial',
            'profile' => 'rotating_three_shift', 'policy' => 'ethiopia_private',
        ],
        'construction' => [
            'label' => 'Construction / Engineering projects', 'label_am' => 'ግንባታ / የምሕንድስና ፕሮጀክቶች',
            'base' => 'manufacturing', 'icon' => 'hard-hat', 'group' => 'industrial',
            'profile' => 'six_day_week', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Site Operations', 'Project Management', 'Engineering', 'Safety (HSE)', 'Procurement', 'Equipment & Machinery', 'Finance', 'HR'],
                'positions' => ['Project Director', 'Project Manager', 'Site Engineer', 'Foreman', 'Surveyor', 'Skilled Worker', 'Laborer', 'Guard'],
                'allowances' => [
                    ['name' => 'Site allowance', 'name_am' => 'የሳይት አበል'],
                ],
                'settings' => ['employee_number_format' => 'CON-{SEQ:4}'],
            ],
        ],
        'agriculture' => [
            'label' => 'Agriculture / Agro-processing', 'label_am' => 'ግብርና / የግብርና ምርት ማቀነባበሪያ',
            'base' => 'manufacturing', 'icon' => 'sprout', 'group' => 'industrial',
            'profile' => 'seasonal_agricultural', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Farm Operations', 'Irrigation', 'Agronomy', 'Harvest & Processing', 'Logistics', 'Machinery', 'Finance', 'HR'],
                'positions' => ['Farm Manager', 'Agronomist', 'Field Supervisor', 'Machine Operator', 'Farm Worker', 'Storekeeper', 'Guard'],
                'settings' => ['employee_number_format' => 'AGR-{SEQ:4}'],
            ],
        ],

        // ── Hospitality & trade → hotel ──────────────────────────────
        'hotel' => [
            'label' => 'Hotel / Hospitality', 'label_am' => 'ሆቴል',
            'base' => 'hotel', 'icon' => 'building-hotel', 'group' => 'hospitality_trade',
            'profile' => 'continuous_24_7', 'policy' => 'ethiopia_private',
        ],
        'restaurant' => [
            'label' => 'Restaurant / Catering', 'label_am' => 'ምግብ ቤት / ምግብ አቅርቦት',
            'base' => 'hotel', 'icon' => 'utensils', 'group' => 'hospitality_trade',
            'profile' => 'extended_hours_two_shift', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Kitchen', 'Service', 'Bar', 'Catering Operations', 'Purchasing & Stores', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Head Chef', 'Sous Chef', 'Cook', 'Kitchen Assistant', 'Waiter', 'Cashier', 'Steward'],
                'settings' => ['employee_number_format' => 'RST-{SEQ:4}'],
            ],
        ],
        'retail' => [
            'label' => 'Retail', 'label_am' => 'ችርቻሮ',
            'base' => 'hotel', 'icon' => 'shopping-bag', 'group' => 'hospitality_trade',
            'profile' => 'extended_hours_two_shift', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Store Operations', 'Sales', 'Inventory & Stock', 'Cashiering', 'Customer Service', 'Finance', 'HR'],
                'positions' => ['Store Manager', 'Assistant Manager', 'Supervisor', 'Sales Associate', 'Cashier', 'Stock Clerk', 'Guard'],
                'settings' => ['employee_number_format' => 'RTL-{SEQ:4}'],
            ],
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distribution', 'label_am' => 'ጅምላ ንግድ',
            'base' => 'hotel', 'icon' => 'package', 'group' => 'hospitality_trade',
            'profile' => 'six_day_week', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Warehouse', 'Distribution', 'Sales', 'Procurement', 'Fleet', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Warehouse Manager', 'Sales Manager', 'Sales Representative', 'Storekeeper', 'Driver', 'Loader'],
                'settings' => ['employee_number_format' => 'WHL-{SEQ:4}'],
            ],
        ],

        // ── Services & technology → general ──────────────────────────
        'professional_services' => [
            'label' => 'Professional Services / Consulting', 'label_am' => 'የሙያ አገልግሎት / ማማከር',
            'base' => 'general', 'icon' => 'briefcase', 'group' => 'services_tech',
            'profile' => 'flexible_hybrid', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ["Partners' Office", 'Advisory & Consulting', 'Audit & Assurance', 'Business Development', 'Finance', 'HR', 'IT', 'Administration'],
                'positions' => ['Managing Partner', 'Partner', 'Director', 'Senior Manager', 'Manager', 'Senior Consultant', 'Consultant', 'Analyst', 'Administrative Officer'],
                'settings' => ['employee_number_format' => 'PRO-{SEQ:4}'],
            ],
        ],
        'logistics' => [
            'label' => 'Logistics / Warehousing', 'label_am' => 'ሎጂስቲክስ / መጋዘን',
            'base' => 'general', 'icon' => 'truck', 'group' => 'services_tech',
            'profile' => 'six_day_week', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Operations', 'Fleet Management', 'Warehousing', 'Customs & Clearing', 'Sales', 'Finance', 'HR', 'IT'],
                'positions' => ['General Manager', 'Operations Manager', 'Dispatcher', 'Fleet Supervisor', 'Driver', 'Warehouse Clerk', 'Customs Officer'],
                'settings' => ['employee_number_format' => 'LOG-{SEQ:4}'],
            ],
        ],
        'transport' => [
            'label' => 'Transport', 'label_am' => 'ትራንስፖርት',
            'base' => 'general', 'icon' => 'bus', 'group' => 'services_tech',
            'profile' => 'extended_hours_two_shift', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Operations', 'Fleet Maintenance', 'Route Planning', 'Ticketing', 'Safety', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Operations Manager', 'Dispatcher', 'Driver', 'Conductor', 'Mechanic', 'Ticket Clerk'],
                'settings' => ['employee_number_format' => 'TRN-{SEQ:4}'],
            ],
        ],
        'telecom' => [
            'label' => 'Telecommunications', 'label_am' => 'ቴሌኮሙኒኬሽን',
            'base' => 'general', 'icon' => 'signal', 'group' => 'services_tech',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Network Operations', 'Engineering', 'IT', 'Customer Service', 'Sales & Marketing', 'Billing', 'Finance', 'HR'],
                'positions' => ['CEO', 'CTO', 'Manager', 'Network Engineer', 'Field Technician', 'Customer Service Rep', 'Sales Agent'],
                'settings' => ['employee_number_format' => 'TEL-{SEQ:4}'],
            ],
        ],
        'security_company' => [
            'label' => 'Security / Facilities Management', 'label_am' => 'ጥበቃ / የፋሲሊቲ አስተዳደር',
            'base' => 'general', 'icon' => 'shield-check', 'group' => 'services_tech',
            'profile' => 'continuous_24_7', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Guarding Operations', 'Cleaning & Facilities', 'Training', 'Client Relations', 'Control Room', 'Logistics', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Operations Manager', 'Site Supervisor', 'Shift Leader', 'Security Guard', 'Cleaner', 'Control Room Operator'],
                'settings' => ['employee_number_format' => 'SEC-{SEQ:4}'],
            ],
        ],
        'bpo' => [
            'label' => 'Customer Support / Contact Center', 'label_am' => 'የደንበኞች አገልግሎት / ጥሪ ማዕከል',
            'base' => 'general', 'icon' => 'headset', 'group' => 'services_tech',
            'profile' => 'extended_hours_two_shift', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Contact Center', 'Quality Assurance', 'Workforce Management', 'Training', 'IT', 'Finance', 'HR'],
                'positions' => ['Center Manager', 'Team Leader', 'Quality Analyst', 'Senior Agent', 'Agent', 'Trainer', 'IT Support'],
                'settings' => ['employee_number_format' => 'BPO-{SEQ:4}'],
            ],
        ],
        'technology_company' => [
            'label' => 'Technology / Software Company', 'label_am' => 'የቴክኖሎጂ / ሶፍትዌር ድርጅት',
            'base' => 'general', 'icon' => 'cpu', 'group' => 'services_tech',
            'profile' => 'flexible_hybrid', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Engineering', 'Product', 'Design', 'QA', 'DevOps', 'Sales', 'Customer Success', 'Finance', 'HR'],
                'positions' => ['CEO', 'CTO', 'Engineering Manager', 'Senior Engineer', 'Software Engineer', 'Product Manager', 'Designer', 'QA Engineer'],
                'settings' => ['employee_number_format' => 'TECH-{SEQ:4}'],
            ],
        ],

        // ── General business → general ───────────────────────────────
        'small_business' => [
            'label' => 'Small or Medium Business', 'label_am' => 'አነስተኛና መካከለኛ ንግድ',
            'base' => 'general', 'icon' => 'store', 'group' => 'other',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Management', 'Operations', 'Sales', 'Finance & Administration'],
                'positions' => ['General Manager', 'Supervisor', 'Officer', 'Accountant', 'Sales Staff', 'Support Staff'],
            ],
        ],
        'multi_branch' => [
            'label' => 'Multi-Branch Enterprise', 'label_am' => 'ባለብዙ ቅርንጫፍ ድርጅት',
            'base' => 'general', 'icon' => 'network', 'group' => 'other',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
            'overrides' => [
                'departments' => ['Executive Office', 'Branch Operations', 'Regional Coordination', 'Finance', 'Human Resources', 'Procurement & Logistics', 'IT', 'Internal Audit'],
                'positions' => ['Chief Executive Officer', 'Regional Manager', 'Branch Manager', 'Assistant Branch Manager', 'Supervisor', 'Officer', 'Clerk'],
                'settings' => ['employee_number_format' => 'MBE-{SEQ:4}'],
            ],
        ],
        'custom' => [
            'label' => 'Custom Organization', 'label_am' => 'ብጁ ድርጅት',
            'base' => 'general', 'icon' => 'settings-2', 'group' => 'other',
            'profile' => 'standard_office', 'policy' => 'ethiopia_private',
        ],
    ];

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(self::INDUSTRIES);
    }

    public function has(string $key): bool
    {
        return isset(self::INDUSTRIES[$key]);
    }

    /**
     * @return array{key: string, label: string, label_am: string, base: string, icon: string, group: string, profile: string, policy: string, overrides: array<string, mixed>}|null
     */
    public function find(string $key): ?array
    {
        $entry = self::INDUSTRIES[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $entry['label'],
            'label_am' => $entry['label_am'],
            'base' => $entry['base'],
            'icon' => $entry['icon'],
            'group' => $entry['group'],
            'profile' => $entry['profile'],
            'policy' => $entry['policy'],
            'overrides' => $entry['overrides'] ?? [],
        ];
    }

    /**
     * The catalog as a flat list for a picker, in declared order.
     *
     * @return array<int, array{key: string, label: string, label_am: string, base: string, icon: string, group: string, profile: string, policy: string}>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::INDUSTRIES as $key => $entry) {
            $out[] = [
                'key' => $key,
                'label' => $entry['label'],
                'label_am' => $entry['label_am'],
                'base' => $entry['base'],
                'icon' => $entry['icon'],
                'group' => $entry['group'],
                'profile' => $entry['profile'],
                'policy' => $entry['policy'],
            ];
        }

        return $out;
    }
}
