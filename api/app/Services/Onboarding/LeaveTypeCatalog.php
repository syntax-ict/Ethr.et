<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\Payroll\StatutoryRules;
use Illuminate\Support\Carbon;

/**
 * Statutory-leaning defaults for the leave-type codes an organization template
 * can reference by name alone.
 *
 * Where Labour Proclamation No. 1156/2019 sets a floor (annual leave and its
 * seniority step, maternity, paternity) the days are not written here: they
 * are the statutory parameters in force (`leave.*`, see STATUTORY_DAYS), so
 * the law lives in one place. Codes the proclamation does not cover carry
 * conventional Ethiopian employer defaults and are expected to be edited by the
 * tenant during the review step.
 *
 * Templates may override any field by supplying an object instead of a string.
 */
final class LeaveTypeCatalog
{
    /** @var array<string, array<string, mixed>> */
    private const DEFAULTS = [
        'annual' => [
            'name' => 'Annual Leave',
            'name_am' => 'ዓመታዊ ፈቃድ',
            // Days and the Art. 77(1) seniority step: `leave.annual.*`.
            'accrual_type' => 'monthly',
            'carry_forward' => true,
            'max_carry_days' => 16,
            'is_paid' => true,
            'min_notice_days' => 7,
            'sort_order' => 10,
        ],
        'sick' => [
            'name' => 'Sick Leave',
            'name_am' => 'የሕመም ፈቃድ',
            'default_days' => 30,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'requires_attachment' => true,
            // Art. 85: full, then half, then no pay, within twelve months.
            'follows_art85' => true,
            'sort_order' => 20,
        ],
        'maternity' => [
            'name' => 'Maternity Leave',
            'name_am' => 'የወሊድ ፈቃድ',
            // Days: `leave.maternity.days`.
            'accrual_type' => 'one_time',
            // Art. 88: 120 consecutive days, so calendar days, not working days.
            'counts_calendar_days' => true,
            'is_paid' => true,
            'requires_attachment' => true,
            'gender_restriction' => 'female',
            'sort_order' => 30,
        ],
        'paternity' => [
            'name' => 'Paternity Leave',
            'name_am' => 'የአባትነት ፈቃድ',
            // Days: `leave.paternity.days`.
            'accrual_type' => 'one_time',
            'is_paid' => true,
            'gender_restriction' => 'male',
            'sort_order' => 40,
        ],
        'bereavement' => [
            'name' => 'Bereavement Leave',
            'name_am' => 'የሐዘን ፈቃድ',
            'default_days' => 3,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'sort_order' => 50,
        ],
        'marriage' => [
            'name' => 'Marriage Leave',
            'name_am' => 'የጋብቻ ፈቃድ',
            'default_days' => 3,
            'accrual_type' => 'one_time',
            'is_paid' => true,
            'sort_order' => 60,
        ],
        'study' => [
            'name' => 'Study Leave',
            'name_am' => 'የትምህርት ፈቃድ',
            'default_days' => 10,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'requires_attachment' => true,
            'min_notice_days' => 14,
            'sort_order' => 70,
        ],
        'sabbatical' => [
            'name' => 'Sabbatical Leave',
            'name_am' => 'የምርምር እረፍት',
            'default_days' => 180,
            'accrual_type' => 'one_time',
            'is_paid' => true,
            'requires_attachment' => true,
            'min_notice_days' => 90,
            'sort_order' => 80,
        ],
        'injury' => [
            'name' => 'Work Injury Leave',
            'name_am' => 'የስራ ላይ አደጋ ፈቃድ',
            'default_days' => 30,
            'accrual_type' => 'one_time',
            'is_paid' => true,
            'requires_attachment' => true,
            'sort_order' => 90,
        ],
        'r_and_r' => [
            'name' => 'Rest & Recuperation',
            'name_am' => 'የእረፍትና ማገገሚያ ፈቃድ',
            'default_days' => 5,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'sort_order' => 100,
        ],
        'unpaid' => [
            'name' => 'Unpaid Leave',
            'name_am' => 'ያለክፍያ ፈቃድ',
            'default_days' => 0,
            'accrual_type' => 'annual',
            'is_paid' => false,
            'min_notice_days' => 14,
            'sort_order' => 110,
        ],
        // Not time off: away on the organisation's business. Draws on no
        // balance, and explains the days it covers like any approved leave.
        'official_duty' => [
            'name' => 'Official Duty / Business Trip',
            'name_am' => 'የሥራ ጉዞ / ተልዕኮ',
            'kind' => 'duty',
            'default_days' => 0,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'sort_order' => 120,
        ],
        // An absence the organisation excuses, paid. How a reviewed absence is
        // closed without deducting it.
        'excused' => [
            'name' => 'Excused Absence',
            'name_am' => 'የተፈቀደ መቅረት',
            'kind' => 'excused',
            'default_days' => 0,
            'accrual_type' => 'annual',
            'is_paid' => true,
            'sort_order' => 130,
        ],
    ];

    /**
     * The statutory parameter that gives a code its days: the law's floor.
     * A leave type with one of these codes may grant more, never less
     * (Store/UpdateLeaveTypeRequest).
     *
     * @var array<string, string>
     */
    public const STATUTORY_DAYS = [
        'annual' => 'leave.annual.base_days',
        'maternity' => 'leave.maternity.days',
        'paternity' => 'leave.paternity.days',
    ];

    public function __construct(private readonly StatutoryRules $rules) {}

    /**
     * Provisioned for every organisation whatever its template lists:
     * attendance reconciliation resolves trips and excused absences through
     * them.
     *
     * @var list<string>
     */
    public const ALWAYS = ['official_duty', 'excused'];

    /** Provisioned too, for an organisation with no unpaid type of its own. */
    public const UNPAID = 'unpaid';

    /**
     * Resolve a template entry — a bare code string, or an object that overrides
     * catalog fields — into a full leave-type definition.
     *
     * @param  string|array<string, mixed>  $entry
     * @return array<string, mixed>|null null when the entry names no resolvable code
     */
    public function resolve(string|array $entry): ?array
    {
        $overrides = is_array($entry) ? $entry : [];
        $code = is_string($entry) ? $entry : ($overrides['code'] ?? null);

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $code = strtolower(trim($code));
        $base = self::DEFAULTS[$code] ?? null;

        if ($base === null) {
            // Unknown code: still provisionable, but the tenant has to supply a
            // name and day count rather than inheriting a statutory default.
            if (! isset($overrides['name'])) {
                return null;
            }

            $base = ['default_days' => 0, 'accrual_type' => 'annual', 'is_paid' => true];
        }

        return array_merge([
            'code' => substr($code, 0, 30),
            'kind' => 'leave',
            'name_am' => null,
            'carry_forward' => false,
            'max_carry_days' => null,
            'seniority_every_years' => null,
            'seniority_extra_days' => null,
            'requires_approval' => true,
            'requires_attachment' => false,
            'min_notice_days' => 0,
            'max_consecutive' => null,
            'gender_restriction' => null,
            'is_active' => true,
            'sort_order' => 0,
        ], $base, $this->statutory($code), array_diff_key($overrides, ['code' => null]));
    }

    /**
     * The days, and for annual leave the seniority step, the law sets for a
     * code, from the platform's statutory parameters in force today.
     *
     * @return array<string, int>
     */
    private function statutory(string $code): array
    {
        $parameter = self::STATUTORY_DAYS[$code] ?? null;
        if ($parameter === null) {
            return [];
        }

        $rules = $this->rules->platform(Carbon::today());
        $days = ['default_days' => $rules->int($parameter)];

        if ($code === 'annual') {
            $seniority = $rules->list('leave.annual.seniority');
            $days['seniority_every_years'] = (int) $seniority['every_years'];
            $days['seniority_extra_days'] = (int) $seniority['extra_days'];
        }

        return $days;
    }
}
