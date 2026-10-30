<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Tenant;
use App\Models\User;

/**
 * Ready-made reports, each a ReportEngine config, and which of them suit this
 * organisation.
 *
 * Setup records what kind of organisation this is: `tenants.type` is the
 * industry's base template (government, manufacturing, ngo, ...) and
 * `settings.configuration_profile.workforce_profile` how its people work
 * (office week, rotating shifts, field staff, ...). A preset naming either is
 * recommended when it matches; a preset naming neither is standard and shown
 * to everyone. Until 2026-10-29 the Reports page offered the same four cards
 * to a woreda administration and a three-shift factory, and no report read
 * what setup had recorded.
 */
final class ReportPresets
{
    /** Workforce profiles where overtime and shift cover are the daily question. */
    private const SHIFT_PROFILES = ['six_day_week', 'rotating_three_shift', 'extended_hours_two_shift', 'continuous_24_7', 'seasonal_agricultural', 'weekend'];

    /**
     * `kinds` are base templates (IndustryCatalog `base`), `profiles` are
     * WorkforceProfileCatalog keys; both empty means standard.
     */
    private const PRESETS = [
        ['key' => 'monthly_timesheet', 'config' => ['source' => 'timesheet', 'sort_by' => 'employee_name'], 'kinds' => [], 'profiles' => []],
        ['key' => 'absences', 'config' => ['source' => 'absences'], 'kinds' => [], 'profiles' => []],
        ['key' => 'lateness_by_department', 'config' => ['source' => 'attendance', 'filters' => ['status' => 'late'], 'group_by' => 'department'], 'kinds' => [], 'profiles' => []],
        ['key' => 'headcount_by_department', 'config' => ['source' => 'employees', 'group_by' => 'department'], 'kinds' => [], 'profiles' => []],
        ['key' => 'leave_balances', 'config' => ['source' => 'leave', 'sort_by' => 'employee_name'], 'kinds' => [], 'profiles' => []],
        ['key' => 'payroll_by_period', 'config' => ['source' => 'payroll', 'group_by' => 'period'], 'kinds' => [], 'profiles' => []],

        ['key' => 'staffing_by_grade', 'config' => ['source' => 'employees', 'columns' => ['name', 'employee_code', 'position', 'grade', 'department', 'status'], 'group_by' => 'grade', 'sort_by' => 'grade'], 'kinds' => ['government', 'university', 'hospital'], 'profiles' => []],
        ['key' => 'staffing_by_position', 'config' => ['source' => 'employees', 'columns' => ['name', 'employee_code', 'position', 'grade', 'department'], 'group_by' => 'position', 'sort_by' => 'position'], 'kinds' => ['government', 'university', 'hospital', 'bank'], 'profiles' => []],
        ['key' => 'overtime_by_department', 'config' => ['source' => 'timesheet', 'columns' => ['employee_name', 'department', 'worked_hours', 'overtime_hours'], 'group_by' => 'department', 'sort_by' => 'overtime_hours', 'sort_dir' => 'desc'], 'kinds' => ['manufacturing', 'hospital', 'hotel'], 'profiles' => self::SHIFT_PROFILES],
        ['key' => 'attendance_by_branch', 'config' => ['source' => 'timesheet', 'columns' => ['employee_name', 'branch', 'work_days', 'present_days', 'late_days', 'absent_days'], 'group_by' => 'branch'], 'kinds' => ['bank', 'hotel', 'manufacturing'], 'profiles' => ['field_distributed']],
        ['key' => 'payroll_by_cost_center', 'config' => ['source' => 'payroll', 'columns' => ['employee_name', 'cost_center', 'period', 'gross_cents', 'employer_pension_cents', 'net_cents'], 'group_by' => 'cost_center'], 'kinds' => ['ngo', 'university'], 'profiles' => []],
        ['key' => 'payroll_by_branch', 'config' => ['source' => 'payroll', 'columns' => ['employee_name', 'branch', 'period', 'gross_cents', 'income_tax_cents', 'net_cents'], 'group_by' => 'branch'], 'kinds' => ['bank', 'hotel'], 'profiles' => []],
    ];

    public function __construct(private readonly ReportEngine $engine) {}

    /** @return list<array{key: string, config: array<string, mixed>, kinds: list<string>, profiles: list<string>}> */
    private function catalogue(): array
    {
        return self::PRESETS;
    }

    /**
     * Every preset `$viewer` may run, recommended ones first, each saying
     * whether it is standard, recommended for this organisation, or neither.
     *
     * @return array{organisation: array{type: string|null, workforce_profile: string|null}, presets: list<array{key: string, source: string, config: array<string, mixed>, standard: bool, recommended: bool}>}
     *
     * @scramble-return array{organisation: array{type: string|null, workforce_profile: string|null}, presets: list<array{key: string, source: string, config: array<string, mixed>, standard: bool, recommended: bool}>}
     */
    public function for(Tenant $tenant, User $viewer): array
    {
        $type = is_string($tenant->type) && $tenant->type !== '' ? $tenant->type : null;
        $profile = data_get($tenant->settings, 'configuration_profile.workforce_profile');
        $profile = is_string($profile) && $profile !== '' ? $profile : null;

        $presets = [];
        foreach ($this->catalogue() as $preset) {
            $source = (string) $preset['config']['source'];
            if (! $this->engine->permits($viewer, $source)) {
                continue;
            }

            $standard = $preset['kinds'] === [] && $preset['profiles'] === [];
            $presets[] = [
                'key' => $preset['key'],
                'source' => $source,
                'config' => $preset['config'],
                'standard' => $standard,
                'recommended' => ! $standard && (
                    ($type !== null && in_array($type, $preset['kinds'], true))
                    || ($profile !== null && in_array($profile, $preset['profiles'], true))
                ),
            ];
        }

        // Recommended, then standard, then the rest; the catalogue order within each.
        usort($presets, fn (array $a, array $b): int => [! $a['recommended'], ! $a['standard']] <=> [! $b['recommended'], ! $b['standard']]);

        return [
            'organisation' => ['type' => $type, 'workforce_profile' => $profile],
            'presets' => $presets,
        ];
    }
}
