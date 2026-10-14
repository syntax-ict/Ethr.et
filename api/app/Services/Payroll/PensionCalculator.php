<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PensionRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Pension contributions from the rule in force (`pension_rules`), not from
 * constants: the law changes, and a past period must be recomputed under the
 * rule of its own time.
 *
 * Resolution mirrors TaxCalculator: the organisation's own rule for its
 * scheme, then the platform rule, then the fallback below — which exists only
 * for a database with no rule at all, as TaxCalculator's hard-coded ladder
 * does. The constructor rates are that fallback.
 */
final class PensionCalculator
{
    public const SCHEMES = ['private', 'public', 'public_military'];

    /** basic: basic salary after proration; regular_salary: plus fixed allowances (research P7, open). */
    public const BASES = ['basic', 'regular_salary'];

    public const FALLBACK_REFERENCE = 'ETHR fallback — no pension rule in force';

    /** @var array<string, PensionRuleSnapshot> */
    private array $ruleCache = [];

    public function __construct(
        private readonly float $employeeRate = 7.0,
        private readonly float $employerRate = 11.0,
    ) {}

    /**
     * The fallback rates applied to an amount, with no rule. Kept for callers
     * that have no tenant or date.
     *
     * @return array{employee_cents: int, employer_cents: int}
     */
    public function calculate(int $basicSalaryCents): array
    {
        return [
            'employee_cents' => (int) round($basicSalaryCents * $this->employeeRate / 100),
            'employer_cents' => (int) round($basicSalaryCents * $this->employerRate / 100),
        ];
    }

    /**
     * The rule in force on `$asOf` for the scheme — pass the payroll period
     * start, as for tax, so reprocessing an old period reproduces it.
     */
    public function rule(?int $tenantId, string $scheme, CarbonInterface $asOf): PensionRuleSnapshot
    {
        $scheme = in_array($scheme, self::SCHEMES, true) ? $scheme : 'private';
        $key = ($tenantId ?? 'platform').'|'.$scheme.'@'.$asOf->format('Y-m-d');

        if (isset($this->ruleCache[$key])) {
            return $this->ruleCache[$key];
        }

        $row = ($tenantId !== null ? $this->inForce($tenantId, $scheme, $asOf) : null)
            ?? $this->inForce(null, $scheme, $asOf);

        return $this->ruleCache[$key] = $row !== null
            ? PensionRuleSnapshot::fromModel($row)
            : new PensionRuleSnapshot(
                scheme: $scheme,
                employeeRate: $this->employeeRate,
                employerRate: $this->employerRate,
                ceilingCents: null,
                floorCents: null,
                base: 'basic',
                exemptNonNationals: true,
                minServiceDays: null,
                legalReference: self::FALLBACK_REFERENCE,
                effectiveFrom: null,
                source: 'fallback',
                rulePublicId: null,
            );
    }

    /**
     * Apply a rule to one employee for one period.
     *
     * @return array{
     *     employee_cents: int, employer_cents: int, exempt: bool, exempt_reason: string|null,
     *     pensionable_cents: int, contributory_cents: int,
     * }
     */
    public function apply(PensionRuleSnapshot $rule, Employee $employee, int $basicCents, int $fixedAllowancesCents, CarbonInterface $periodEnd): array
    {
        $pensionable = $rule->base === 'regular_salary' ? $basicCents + $fixedAllowancesCents : $basicCents;
        $reason = $this->exemption($rule, $employee, $periodEnd);

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
            'employee_cents' => (int) round($contributory * $rule->employeeRate / 100),
            'employer_cents' => (int) round($contributory * $rule->employerRate / 100),
            'exempt' => false,
            'exempt_reason' => null,
            'pensionable_cents' => $pensionable,
            'contributory_cents' => $contributory,
        ];
    }

    /**
     * Why the rule does not cover this employee, or null when it does.
     *
     * Nationality is free text on the employee; blank counts as Ethiopian
     * (the forms default to it). A foreign national of Ethiopian origin may be
     * covered under Proclamation 270/2002 — how is not settled (research P8),
     * so such a person is treated as foreign until that is answered.
     */
    private function exemption(PensionRuleSnapshot $rule, Employee $employee, CarbonInterface $periodEnd): ?string
    {
        if ($rule->exemptNonNationals && ! self::isEthiopian($employee->nationality)) {
            return 'non_national';
        }

        if ($rule->minServiceDays !== null && $employee->hire_date !== null) {
            $served = Carbon::parse($employee->hire_date)->startOfDay()->diffInDays(Carbon::parse($periodEnd)->startOfDay()) + 1;
            if ($served < $rule->minServiceDays) {
                return 'min_service';
            }
        }

        return null;
    }

    public static function isEthiopian(?string $nationality): bool
    {
        $value = mb_strtolower(trim((string) $nationality));

        return $value === '' || in_array($value, ['ethiopian', 'ethiopia', 'et', 'eth', 'ኢትዮጵያዊ', 'ኢትዮጵያዊት', 'ኢትዮጵያ'], true);
    }

    private function inForce(?int $tenantId, string $scheme, CarbonInterface $asOf): ?PensionRule
    {
        $on = $asOf->format('Y-m-d');

        return PensionRule::query()
            ->withoutGlobalScope('tenant')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id'),
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
