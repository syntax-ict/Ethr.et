<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * Statutory-leaning defaults for the leave-type codes an organization template
 * can reference by name alone.
 *
 * Day counts follow the Ethiopian Labour Proclamation No. 1156/2019 where it
 * sets a floor (annual 16 days, maternity 30 prenatal + 90 postnatal, paternity
 * 3 days, mourning 3 days). Codes the proclamation does not cover carry
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
            'default_days' => 16,
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
            'sort_order' => 20,
        ],
        'maternity' => [
            'name' => 'Maternity Leave',
            'name_am' => 'የወሊድ ፈቃድ',
            'default_days' => 120,
            'accrual_type' => 'one_time',
            'is_paid' => true,
            'requires_attachment' => true,
            'gender_restriction' => 'female',
            'sort_order' => 30,
        ],
        'paternity' => [
            'name' => 'Paternity Leave',
            'name_am' => 'የአባትነት ፈቃድ',
            'default_days' => 3,
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
    ];

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
            'name_am' => null,
            'carry_forward' => false,
            'max_carry_days' => null,
            'requires_approval' => true,
            'requires_attachment' => false,
            'min_notice_days' => 0,
            'max_consecutive' => null,
            'gender_restriction' => null,
            'is_active' => true,
            'sort_order' => 0,
        ], $base, array_diff_key($overrides, ['code' => null]));
    }

    /** @return array<int, string> */
    public function knownCodes(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
