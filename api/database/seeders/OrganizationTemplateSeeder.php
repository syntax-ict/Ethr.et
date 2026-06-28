<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationTemplateSeeder extends Seeder
{
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
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'study'],
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
                    'shifts' => [
                        ['name' => 'Regular', 'start' => '08:00', 'end' => '17:00', 'days' => '1,2,3,4,5'],
                        ['name' => 'Saturday', 'start' => '08:00', 'end' => '12:00', 'days' => '6'],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement'],
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
                    'shifts' => [
                        ['name' => 'Morning', 'start' => '07:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6,0'],
                        ['name' => 'Afternoon', 'start' => '14:00', 'end' => '21:00', 'days' => '1,2,3,4,5,6,0'],
                        ['name' => 'Night', 'start' => '21:00', 'end' => '07:00', 'days' => '1,2,3,4,5,6,0'],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'study'],
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
                    'shifts' => [
                        ['name' => 'Day', 'start' => '06:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6'],
                        ['name' => 'Swing', 'start' => '14:00', 'end' => '22:00', 'days' => '1,2,3,4,5,6'],
                        ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'days' => '1,2,3,4,5,6'],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'injury'],
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
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement', 'r_and_r'],
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
                    'shifts' => [
                        ['name' => 'Morning', 'start' => '06:00', 'end' => '14:00', 'days' => '1,2,3,4,5,6,0'],
                        ['name' => 'Afternoon', 'start' => '14:00', 'end' => '22:00', 'days' => '1,2,3,4,5,6,0'],
                        ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'days' => '1,2,3,4,5,6,0'],
                    ],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity'],
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
                    'shifts' => [['name' => 'Regular', 'start' => '08:00', 'end' => '17:00', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'sabbatical', 'study'],
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
                    'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
                    'leave_types' => ['annual', 'sick', 'maternity', 'paternity', 'bereavement'],
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
