<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * The twenty-seven selectable industries, each an alias onto one of the eight
 * maintained organization templates plus an optional thin override patch.
 *
 * See ONBOARDING_V2.md decision D3: adding an industry is adding a row here, not
 * authoring a ninth template. `base` names the OrganizationTemplate slug the
 * industry inherits; `overrides` is a partial `template_data` merged on top by
 * IndustryProfileResolver (list sections replace, `settings` deep-merges).
 *
 * Labels carry an Amharic variant inline, the same way OrganizationTemplate
 * stores its display name, rather than through translation keys.
 */
final class IndustryCatalog
{
    /**
     * @var array<string, array{
     *     label: string,
     *     label_am: string,
     *     base: string,
     *     icon: string,
     *     group: string,
     *     overrides?: array<string, mixed>
     * }>
     */
    private const INDUSTRIES = [
        // ── Public sector → government ──────────────────────────────
        'federal_government' => [
            'label' => 'Federal Government', 'label_am' => 'የፌዴራል መንግስት',
            'base' => 'government', 'icon' => 'landmark', 'group' => 'public_sector',
        ],
        'regional_government' => [
            'label' => 'Regional Government', 'label_am' => 'የክልል መንግስት',
            'base' => 'government', 'icon' => 'map', 'group' => 'public_sector',
        ],
        'city_administration' => [
            'label' => 'City Administration', 'label_am' => 'የከተማ አስተዳደር',
            'base' => 'government', 'icon' => 'building-2', 'group' => 'public_sector',
            'overrides' => [
                'settings' => ['employee_number_format' => 'CITY-{SEQ:4}'],
            ],
        ],
        'woreda_administration' => [
            'label' => 'Woreda Administration', 'label_am' => 'የወረዳ አስተዳደር',
            'base' => 'government', 'icon' => 'map-pin', 'group' => 'public_sector',
        ],
        'ministry' => [
            'label' => 'Ministry', 'label_am' => 'ሚኒስቴር',
            'base' => 'government', 'icon' => 'building-government', 'group' => 'public_sector',
            'overrides' => [
                'settings' => ['employee_number_format' => 'MIN-{SEQ:4}'],
            ],
        ],

        // ── Education → university ───────────────────────────────────
        'university' => [
            'label' => 'University', 'label_am' => 'ዩኒቨርሲቲ',
            'base' => 'university', 'icon' => 'graduation-cap', 'group' => 'education',
        ],
        'tvet' => [
            'label' => 'TVET College', 'label_am' => 'ቴክኒክና ሙያ ኮሌጅ',
            'base' => 'university', 'icon' => 'wrench', 'group' => 'education',
            'overrides' => [
                'departments' => ['Academic Affairs', 'Workshops & Labs', 'Cooperative Training', 'Student Services', 'Registrar', 'Finance', 'HR', 'Facilities'],
                'positions' => ['Dean', 'Department Head', 'Senior Instructor', 'Instructor', 'Assistant Instructor', 'Lab Technician', 'Admin Staff'],
                'settings' => ['employee_number_format' => 'TVET-{SEQ:4}'],
            ],
        ],
        'school' => [
            'label' => 'School', 'label_am' => 'ትምህርት ቤት',
            'base' => 'university', 'icon' => 'school', 'group' => 'education',
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
        ],
        'health_center' => [
            'label' => 'Health Center / Clinic', 'label_am' => 'ጤና ጣቢያ / ክሊኒክ',
            'base' => 'hospital', 'icon' => 'stethoscope', 'group' => 'healthcare',
            'overrides' => [
                'departments' => ['Outpatient', 'Emergency', 'Pharmacy', 'Laboratory', 'Nursing', 'Administration'],
                'positions' => ['Medical Director', 'General Practitioner', 'Head Nurse', 'Nurse', 'Lab Technician', 'Pharmacist', 'Receptionist'],
                'settings' => ['employee_number_format' => 'HC-{SEQ:4}'],
            ],
        ],

        // ── Nonprofit → ngo ──────────────────────────────────────────
        'ngo' => [
            'label' => 'NGO / International Organization', 'label_am' => 'መንግስታዊ ያልሆነ ድርጅት',
            'base' => 'ngo', 'icon' => 'globe', 'group' => 'nonprofit',
        ],

        // ── Financial → bank ─────────────────────────────────────────
        'bank' => [
            'label' => 'Bank', 'label_am' => 'ባንክ',
            'base' => 'bank', 'icon' => 'building-bank', 'group' => 'financial',
        ],
        'insurance' => [
            'label' => 'Insurance Company', 'label_am' => 'የመድን ድርጅት',
            'base' => 'bank', 'icon' => 'shield', 'group' => 'financial',
            'overrides' => [
                'departments' => ['Underwriting', 'Claims', 'Actuarial', 'Reinsurance', 'Sales & Marketing', 'Finance', 'Risk & Compliance', 'IT', 'HR'],
                'positions' => ['CEO', 'VP', 'Director', 'Manager', 'Senior Officer', 'Underwriter', 'Claims Officer', 'Agent', 'Clerk'],
                'settings' => ['employee_number_format' => 'INS-{SEQ:4}'],
            ],
        ],
        'microfinance' => [
            'label' => 'Microfinance Institution', 'label_am' => 'የአነስተኛ ብድር ተቋም',
            'base' => 'bank', 'icon' => 'coins', 'group' => 'financial',
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
        ],
        'construction' => [
            'label' => 'Construction', 'label_am' => 'ግንባታ',
            'base' => 'manufacturing', 'icon' => 'hard-hat', 'group' => 'industrial',
            'overrides' => [
                'departments' => ['Site Operations', 'Project Management', 'Engineering', 'Safety (HSE)', 'Procurement', 'Equipment & Machinery', 'Finance', 'HR'],
                'positions' => ['Project Director', 'Project Manager', 'Site Engineer', 'Foreman', 'Surveyor', 'Skilled Worker', 'Laborer', 'Guard'],
                'settings' => ['employee_number_format' => 'CON-{SEQ:4}'],
            ],
        ],
        'agriculture' => [
            'label' => 'Agriculture / Agribusiness', 'label_am' => 'ግብርና',
            'base' => 'manufacturing', 'icon' => 'sprout', 'group' => 'industrial',
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
        ],
        'retail' => [
            'label' => 'Retail', 'label_am' => 'ችርቻሮ',
            'base' => 'hotel', 'icon' => 'shopping-bag', 'group' => 'hospitality_trade',
            'overrides' => [
                'departments' => ['Store Operations', 'Sales', 'Inventory & Stock', 'Cashiering', 'Customer Service', 'Finance', 'HR'],
                'positions' => ['Store Manager', 'Assistant Manager', 'Supervisor', 'Sales Associate', 'Cashier', 'Stock Clerk', 'Guard'],
                'settings' => ['employee_number_format' => 'RTL-{SEQ:4}'],
            ],
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distribution', 'label_am' => 'ጅምላ ንግድ',
            'base' => 'hotel', 'icon' => 'package', 'group' => 'hospitality_trade',
            'overrides' => [
                'departments' => ['Warehouse', 'Distribution', 'Sales', 'Procurement', 'Fleet', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Warehouse Manager', 'Sales Manager', 'Sales Representative', 'Storekeeper', 'Driver', 'Loader'],
                'settings' => ['employee_number_format' => 'WHL-{SEQ:4}'],
            ],
        ],

        // ── Services & technology → general ──────────────────────────
        'logistics' => [
            'label' => 'Logistics / Freight', 'label_am' => 'ሎጂስቲክስ',
            'base' => 'general', 'icon' => 'truck', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Operations', 'Fleet Management', 'Warehousing', 'Customs & Clearing', 'Sales', 'Finance', 'HR', 'IT'],
                'positions' => ['General Manager', 'Operations Manager', 'Dispatcher', 'Fleet Supervisor', 'Driver', 'Warehouse Clerk', 'Customs Officer'],
                'settings' => ['employee_number_format' => 'LOG-{SEQ:4}'],
            ],
        ],
        'transport' => [
            'label' => 'Transport', 'label_am' => 'ትራንስፖርት',
            'base' => 'general', 'icon' => 'bus', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Operations', 'Fleet Maintenance', 'Route Planning', 'Ticketing', 'Safety', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Operations Manager', 'Dispatcher', 'Driver', 'Conductor', 'Mechanic', 'Ticket Clerk'],
                'settings' => ['employee_number_format' => 'TRN-{SEQ:4}'],
            ],
        ],
        'telecom' => [
            'label' => 'Telecommunications', 'label_am' => 'ቴሌኮሙኒኬሽን',
            'base' => 'general', 'icon' => 'signal', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Network Operations', 'Engineering', 'IT', 'Customer Service', 'Sales & Marketing', 'Billing', 'Finance', 'HR'],
                'positions' => ['CEO', 'CTO', 'Manager', 'Network Engineer', 'Field Technician', 'Customer Service Rep', 'Sales Agent'],
                'settings' => ['employee_number_format' => 'TEL-{SEQ:4}'],
            ],
        ],
        'security_company' => [
            'label' => 'Security Company', 'label_am' => 'የጥበቃ ድርጅት',
            'base' => 'general', 'icon' => 'shield-check', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Guarding Operations', 'Training', 'Client Relations', 'Control Room', 'Logistics', 'Finance', 'HR'],
                'positions' => ['General Manager', 'Operations Manager', 'Supervisor', 'Shift Leader', 'Security Guard', 'Control Room Operator'],
                'settings' => ['employee_number_format' => 'SEC-{SEQ:4}'],
            ],
        ],
        'bpo' => [
            'label' => 'BPO / Call Center', 'label_am' => 'BPO / ጥሪ ማዕከል',
            'base' => 'general', 'icon' => 'headset', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Contact Center', 'Quality Assurance', 'Workforce Management', 'Training', 'IT', 'Finance', 'HR'],
                'positions' => ['Center Manager', 'Team Leader', 'Quality Analyst', 'Senior Agent', 'Agent', 'Trainer', 'IT Support'],
                'settings' => ['employee_number_format' => 'BPO-{SEQ:4}'],
            ],
        ],
        'technology_company' => [
            'label' => 'Technology Company', 'label_am' => 'የቴክኖሎጂ ድርጅት',
            'base' => 'general', 'icon' => 'cpu', 'group' => 'services_tech',
            'overrides' => [
                'departments' => ['Engineering', 'Product', 'Design', 'QA', 'DevOps', 'Sales', 'Customer Success', 'Finance', 'HR'],
                'positions' => ['CEO', 'CTO', 'Engineering Manager', 'Senior Engineer', 'Software Engineer', 'Product Manager', 'Designer', 'QA Engineer'],
                'settings' => ['employee_number_format' => 'TECH-{SEQ:4}'],
            ],
        ],

        // ── Custom → general ─────────────────────────────────────────
        'custom' => [
            'label' => 'Custom Organization', 'label_am' => 'ብጁ ድርጅት',
            'base' => 'general', 'icon' => 'settings-2', 'group' => 'other',
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
     * @return array{key: string, label: string, label_am: string, base: string, icon: string, group: string, overrides: array<string, mixed>}|null
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
            'overrides' => $entry['overrides'] ?? [],
        ];
    }

    /**
     * The catalog as a flat list for a picker, in declared order.
     *
     * @return array<int, array{key: string, label: string, label_am: string, base: string, icon: string, group: string}>
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
            ];
        }

        return $out;
    }
}
