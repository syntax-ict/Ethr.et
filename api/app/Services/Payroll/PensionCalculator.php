<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\NationalityStatus;
use App\Exceptions\PayrollRunRefused;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PensionRule;
use App\Services\Tenancy\ProfileContext;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Pension contributions from the rule in force (`pension_rules`), not from
 * constants: the law changes, and a past period must be recomputed under the
 * rule of its own time.
 *
 * Resolution mirrors TaxCalculator: the organisation's own rule for its
 * scheme, then the platform rule for its organisation type, then the platform
 * rule for every type. There is no fallback in code since 2026-10-27: a
 * scheme with no rule in force refuses the run by name. The schemes
 * themselves are the `pension.schemes` statutory parameter.
 */
final class PensionCalculator
{
    /** basic: basic salary after proration; regular_salary: plus fixed allowances (research P7, open). */
    public const BASES = ['basic', 'regular_salary'];

    /** @var array<string, PensionRuleSnapshot> */
    private array $ruleCache = [];

    /**
     * The rule in force on `$asOf` for the scheme — pass the payroll period
     * start, as for tax, so reprocessing an old period reproduces it.
     */
    public function rule(?int $tenantId, string $scheme, CarbonInterface $asOf): PensionRuleSnapshot
    {
        $key = ($tenantId ?? 'platform').'|'.$scheme.'@'.$asOf->format('Y-m-d');

        if (isset($this->ruleCache[$key])) {
            return $this->ruleCache[$key];
        }

        // The organisation's type on this date, then that type's parent: a
        // type that owns no rule of its own is covered by its parent's.
        $lineage = $tenantId === null ? [] : app(ProfileContext::class)->for($tenantId, $asOf)['lineage'];

        $row = $tenantId !== null ? $this->inForce($tenantId, null, $scheme, $asOf) : null;
        $source = 'tenant';
        foreach ($lineage as $type) {
            if ($row !== null) {
                break;
            }
            $row = $this->inForce(null, $type, $scheme, $asOf);
            $source = 'platform_type';
        }
        if ($row === null) {
            $row = $this->inForce(null, null, $scheme, $asOf);
            $source = 'platform';
        }

        if ($row === null) {
            throw PayrollRunRefused::noRuleInForce("pension.{$scheme}");
        }

        return $this->ruleCache[$key] = PensionRuleSnapshot::fromModel($row, $source);
    }

    /**
     * Apply a rule to one employee for one period.
     *
     * `$appliesTo` is the statutory parameter `pension.applies_to`: which
     * contract types pension covers. A type it marks false is exempt.
     *
     * @param  array<array-key, mixed>|null  $appliesTo
     * @return array{
     *     employee_cents: int, employer_cents: int, exempt: bool, exempt_reason: string|null,
     *     pensionable_cents: int, contributory_cents: int,
     * }
     */
    public function apply(PensionRuleSnapshot $rule, Employee $employee, int $basicCents, int $fixedAllowancesCents, CarbonInterface $periodEnd, ?array $appliesTo = null): array
    {
        $pensionable = $rule->base === 'regular_salary' ? $basicCents + $fixedAllowancesCents : $basicCents;
        $reason = $this->exemption($rule, $employee, $periodEnd) ?? $this->contractExemption($employee, $periodEnd, $appliesTo);

        if ($reason !== null) {
            return [
                'employee_cents' => 0, 'employer_cents' => 0, 'exempt' => true, 'exempt_reason' => $reason,
                'pensionable_cents' => $pensionable, 'contributory_cents' => 0,
            ];
        }

        // Contributions are computed on the pensionable amount held between
        // the rule's floor and ceiling, when it sets them (research P6: none
        // in the current law).
        $contributory = $pensionable;
        if ($rule->ceilingCents !== null) {
            $contributory = min($contributory, $rule->ceilingCents);
        }
        if ($rule->floorCents !== null && $contributory > 0) {
            $contributory = max($contributory, $rule->floorCents);
        }

        return [
            'employee_cents' => Cents::percentOf($contributory, $rule->employeeRate),
            'employer_cents' => Cents::percentOf($contributory, $rule->employerRate),
            'exempt' => false,
            'exempt_reason' => null,
            'pensionable_cents' => $pensionable,
            'contributory_cents' => $contributory,
        ];
    }

    /**
     * Why the rule does not cover this employee, or null when it does.
     *
     * Only `nationality_status` decides — never the free-text nationality,
     * where a typo used to exempt an Ethiopian silently. Unset counts as
     * Ethiopian, the column's default. A foreign national of Ethiopian origin
     * may be covered under Proclamation 270/2002, but how is not settled
     * (research P8), so they are exempt under their own reason until it is.
     */
    private function exemption(PensionRuleSnapshot $rule, Employee $employee, CarbonInterface $periodEnd): ?string
    {
        $status = $employee->nationality_status;
        if ($rule->exemptNonNationals && $status === NationalityStatus::FOREIGN) {
            return 'non_national';
        }
        if ($rule->exemptNonNationals && $status === NationalityStatus::ETHIOPIAN_ORIGIN) {
            return 'ethiopian_origin_unconfirmed';
        }

        if ($rule->minServiceDays !== null && $employee->hire_date !== null) {
            $served = Carbon::parse($employee->hire_date)->startOfDay()->diffInDays(Carbon::parse($periodEnd)->startOfDay()) + 1;
            if ($served < $rule->minServiceDays) {
                return 'min_service';
            }
        }

        return null;
    }

    /**
     * 'contract_type' when `pension.applies_to` excludes the contract the
     * person is on at the period's end, else null. Every contract type is
     * covered today (research P11), so the contract is only looked up once
     * the parameter excludes one.
     *
     * @param  array<array-key, mixed>|null  $appliesTo
     */
    private function contractExemption(Employee $employee, CarbonInterface $periodEnd, ?array $appliesTo): ?string
    {
        if ($appliesTo === null || ! in_array(false, $appliesTo, true)) {
            return null;
        }

        $on = $periodEnd->format('Y-m-d');
        $contract = EmployeeContract::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('start_date', '<=', $on)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $on))
            ->orderByDesc('start_date')
            ->first(['contract_type']);

        $type = $contract?->contract_type->value;

        return $type !== null && ($appliesTo[$type] ?? true) === false ? 'contract_type' : null;
    }

    /**
     * Whether a free-text nationality is a known Ethiopian spelling. No longer
     * decides coverage; NationalityBackfill uses it once, to map the values
     * people had typed before `nationality_status` existed.
     */
    public static function isEthiopian(?string $nationality): bool
    {
        $value = mb_strtolower(trim((string) $nationality));

        return $value === '' || in_array($value, ['ethiopian', 'ethiopia', 'et', 'eth', 'ኢትዮጵያዊ', 'ኢትዮጵያዊት', 'ኢትዮጵያ'], true);
    }

    /** One owner's rule in force: an organisation's, a type's on the platform, or the platform's for every type. */
    private function inForce(?int $tenantId, ?string $organizationType, string $scheme, CarbonInterface $asOf): ?PensionRule
    {
        $on = $asOf->format('Y-m-d');

        return PensionRule::query()
            ->withoutGlobalScope('tenant')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id')->when(
                    $organizationType === null,
                    fn ($q) => $q->whereNull('organization_type'),
                    fn ($q) => $q->where('organization_type', $organizationType),
                ),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->where('scheme', $scheme)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
