<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The eight maintained organization templates.
 *
 * `template_data` is consumed by App\Services\Onboarding\OrganizationProvisioner,
 * which accepts both the original shorthand (bare strings for departments and
 * positions, `start`/`end`/`days` on shifts, bare leave-type codes resolved
 * through LeaveTypeCatalog) and fully-specified objects.
 *
 * The twenty-seven industries the product offers are aliases onto these eight —
 * see ONBOARDING_V2.md decision D3. Adding an industry means adding an alias and
 * a thin override, not a ninth hand-authored configuration.
 */
class OrganizationTemplateSeeder extends Seeder
{
    /**
     * Payroll and attendance defaults shared by every Ethiopian employer.
     *
     * Overtime multipliers and pension rates follow Labour Proclamation
     * 1156/2019 and Pension Proclamations 714/2011 & 907/2015. Tenants can
     * override any of it during the configuration review step.
     *
     * @return array<string, mixed>
     */
    private function baseSettings(string $fiscalYearStart, string $numberPrefix, string $attendanceMethod): array
    {
        return [
            'payroll' => [
                'pay_frequency' => 'monthly',
                'fiscal_year_start' => $fiscalYearStart,
                'pagumen_proration' => 'full_month',
                'pension_employee_rate' => 0.07,
                'pension_employer_rate' => 0.11,
                'overtime_multipliers' => [
                    'weekday' => 1.5,
                    'night' => 1.75,
                    'rest_day' => 2.0,
                    'public_holiday' => 2.5,
                ],
            ],
            'attendance' => [
                'default_method' => $attendanceMethod,
                'grace_minutes' => 15,
            ],
            'employee_number_format' => $numberPrefix.'-{SEQ:4}',
        ];
    }

    /**
     * An eight-rung grade ladder scaled to a sector's entry salary, in ETB
     * minor units (cents) per CLAUDE.md convention 3.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gradeLadder(int $entryMonthlyEtb, float $step): array
    {
        $grades = [];
        $min = $entryMonthlyEtb;

        foreach (['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'] as $i => $roman) {
            $max = (int) round($min * $step);

            $grades[] = [
                'name' => 'Grade '.$roman,
                'min_salary_cents' => $min * 100,
                'max_salary_cents' => $max * 100,
                'sort_order' => ($i + 1) * 10,
            ];

            $min = $max;
        }

        return $grades;
    }

    public function run(): void
    {
        $templates = [
            [
                'name' => 'Government Agency',
                'slug' => 'government',
                'description' => 'Ethiopian government ministries, agencies, and bureaus.',
                'icon' => 'building-government',
                'template_data' => [
                    'departments' => ['Administration', 'Finance', 'Human Resources', 'IT', 'Legal', 'Planning & Development', 'Public Relations'],
                    'positions' => ['Director General', 'Deputy Director', 'Department Head', 'Team Leader', 'Senior Expert', 'Expert', 'Junior Expert', 'Support Staff'],
                    'grades' => $this->gradeLadder(4000, 1.35),
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'study', 'unpaid'],
                    'settings' => $this->baseSettings('hamle', 'GOV', 'biometric'),
                ],
                'sort_order' => 1,
            ],
            [
                'name' => 'Bank / Financial Institution',
                'slug' => 'bank',
                'description' => 'Banks, insurance companies, and microfinance institutions.',
                'icon' => 'building-bank',
                'template_data' => [
                    'departments' => ['Operations', 'Credit', 'Treasury', 'Risk Management', 'Compliance', 'IT', 'Marketing', 'Human Resources', 'Audit'],
                    'positions' => ['President', 'VP', 'Director', 'Manager', 'Senior Officer', 'Officer', 'Junior Officer', 'Clerk', 'Cashier'],
                    'grades' => $this->gradeLadder(8000, 1.4),
                    'shifts' => [
                        ['name' => 'Regular', 'start' => '08:00', 'end' => '17:00', 'days' => '1,2,3,4,5'],
                        ['name' => 'Saturday', 'start' => '08:00', 'end' => '12:00', 'days' => '6', 'break_minutes' => 0],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'marriage'],
                    'settings' => $this->baseSettings('meskerem', 'BNK', 'biometric'),
                ],
                'sort_order' => 2,
            ],
            [
                'name' => 'Hospital / Healthcare',
                'slug' => 'hospital',
                'description' => 'Hospitals, clinics, and healthcare facilities.',
                'icon' => 'building-hospital',
                'template_data' => [
                    'departments' => ['Emergency', 'Surgery', 'Internal Medicine', 'Pediatrics', 'OB/GYN', 'Pharmacy', 'Laboratory', 'Radiology', 'Nursing', 'Administration'],
                    'positions' => ['Medical Director', 'Specialist', 'General Practitioner', 'Head Nurse', 'Nurse', 'Lab Technician', 'Pharmacist', 'Receptionist'],
                    'grades' => $this->gradeLadder(6000, 1.45),
                    'shifts' => [
                        ['name' => 'Morning', 'start' => '07:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 30],
                        ['name' => 'Afternoon', 'start' => '14:00', 'end' => '21:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 30],
                        ['name' => 'Night', 'start' => '21:00', 'end' => '07:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 60],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'study', 'injury'],
                    'settings' => $this->baseSettings('meskerem', 'HSP', 'biometric'),
                ],
                'sort_order' => 3,
            ],
            [
                'name' => 'Manufacturing / Factory',
                'slug' => 'manufacturing',
                'description' => 'Factories, industrial parks, and production facilities.',
                'icon' => 'building-factory',
                'template_data' => [
                    'departments' => ['Production', 'Quality Control', 'Warehouse', 'Maintenance', 'Safety', 'HR', 'Finance', 'Logistics'],
                    'positions' => ['Plant Manager', 'Production Supervisor', 'Quality Inspector', 'Machine Operator', 'Technician', 'Worker', 'Guard'],
                    'grades' => $this->gradeLadder(3500, 1.3),
                    'shifts' => [
                        ['name' => 'Day', 'start' => '06:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6', 'break_minutes' => 30],
                        ['name' => 'Swing', 'start' => '14:00', 'end' => '22:00', 'days' => '1,2,3,4,5,6', 'break_minutes' => 30],
                        ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'days' => '1,2,3,4,5,6', 'break_minutes' => 30],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'injury', 'unpaid'],
                    'settings' => $this->baseSettings('meskerem', 'MFG', 'biometric'),
                ],
                'sort_order' => 4,
            ],
            [
                'name' => 'NGO / International Organization',
                'slug' => 'ngo',
                'description' => 'Non-governmental and international development organizations.',
                'icon' => 'globe',
                'template_data' => [
                    'departments' => ['Programs', 'Finance & Grants', 'HR & Admin', 'M&E', 'Communications', 'IT', 'Procurement', 'Field Operations'],
                    'positions' => ['Country Director', 'Program Manager', 'Project Coordinator', 'M&E Officer', 'Finance Officer', 'Field Officer', 'Driver'],
                    'grades' => $this->gradeLadder(10000, 1.4),
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'r_and_r', 'unpaid'],
                    'settings' => $this->baseSettings('meskerem', 'NGO', 'mobile'),
                ],
                'sort_order' => 5,
            ],
            [
                'name' => 'Hotel / Hospitality',
                'slug' => 'hotel',
                'description' => 'Hotels, resorts, restaurants, and hospitality businesses.',
                'icon' => 'building-hotel',
                'template_data' => [
                    'departments' => ['Front Office', 'Housekeeping', 'F&B Service', 'Kitchen', 'Maintenance', 'Security', 'HR', 'Finance', 'Sales'],
                    'positions' => ['General Manager', 'Department Head', 'Supervisor', 'Receptionist', 'Housekeeper', 'Chef', 'Waiter', 'Guard'],
                    'grades' => $this->gradeLadder(3000, 1.3),
                    'shifts' => [
                        ['name' => 'Morning', 'start' => '06:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 30],
                        ['name' => 'Afternoon', 'start' => '14:00', 'end' => '22:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 30],
                        ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'days' => '1,2,3,4,5,6,0', 'break_minutes' => 30],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'unpaid'],
                    'settings' => $this->baseSettings('meskerem', 'HTL', 'kiosk'),
                ],
                'sort_order' => 6,
            ],
            [
                'name' => 'University / Education',
                'slug' => 'university',
                'description' => 'Universities, colleges, and educational institutions.',
                'icon' => 'graduation-cap',
                'template_data' => [
                    'departments' => ['Academic Affairs', 'Research', 'Student Services', 'Library', 'IT Center', 'Finance', 'HR', 'Registrar', 'Facilities'],
                    'positions' => ['President', 'VP', 'Dean', 'Department Head', 'Professor', 'Lecturer', 'Lab Assistant', 'Admin Staff'],
                    'grades' => $this->gradeLadder(5000, 1.4),
                    'shifts' => [['name' => 'Regular', 'start' => '08:00', 'end' => '17:00', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'sabbatical', 'study', 'bereavement'],
                    'settings' => $this->baseSettings('hamle', 'UNI', 'biometric'),
                ],
                'sort_order' => 7,
            ],
            [
                'name' => 'General Company',
                'slug' => 'general',
                'description' => 'General-purpose template for any type of organization.',
                'icon' => 'building',
                'template_data' => [
                    'departments' => ['Management', 'Operations', 'Finance', 'HR', 'IT', 'Sales', 'Marketing'],
                    'positions' => ['CEO', 'Manager', 'Supervisor', 'Senior Staff', 'Staff', 'Junior Staff', 'Intern'],
                    'grades' => $this->gradeLadder(4000, 1.35),
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'unpaid'],
                    'settings' => $this->baseSettings('meskerem', 'EMP', 'mobile'),
                ],
                'sort_order' => 8,
            ],
        ];

        foreach ($templates as $template) {
            DB::table('organization_templates')->updateOrInsert(
                ['slug' => $template['slug']],
                [
                    'public_id' => (string) Str::ulid(),
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'icon' => $template['icon'],
                    'template_data' => json_encode($template['template_data']),
                    'is_active' => true,
                    'sort_order' => $template['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
