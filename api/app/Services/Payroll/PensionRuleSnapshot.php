<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PensionRule;

/**
 * The pension rule a payroll run applied, as it stood then. Written into each
 * entry's calculation_log (convention #11), so an approved run keeps saying
 * which rate and which law it used even after the rule changes.
 */
final readonly class PensionRuleSnapshot
{
    public function __construct(
        public string $scheme,
        public float $employeeRate,
        public float $employerRate,
        public ?int $ceilingCents,
        public ?int $floorCents,
        public string $base,
        public bool $exemptNonNationals,
        public ?int $minServiceDays,
        public string $legalReference,
        public ?string $effectiveFrom,
        /** tenant, platform or fallback */
        public string $source,
        public ?string $rulePublicId,
    ) {}

    public static function fromModel(PensionRule $rule): self
    {
        return new self(
            scheme: $rule->scheme,
            employeeRate: (float) $rule->employee_rate,
            employerRate: (float) $rule->employer_rate,
            ceilingCents: $rule->ceiling_cents,
            floorCents: $rule->floor_cents,
            base: in_array($rule->base, PensionCalculator::BASES, true) ? $rule->base : 'basic',
            exemptNonNationals: (bool) $rule->exempt_non_nationals,
            minServiceDays: $rule->min_service_days,
            legalReference: $rule->legal_reference,
            effectiveFrom: $rule->effective_from->format('Y-m-d'),
            source: $rule->tenant_id === null ? 'platform' : 'tenant',
            rulePublicId: $rule->public_id,
        );
    }

    /** @return array<string, mixed> */
    public function toLog(): array
    {
        return [
            'scheme' => $this->scheme,
            'employee_rate' => $this->employeeRate,
            'employer_rate' => $this->employerRate,
            'ceiling_cents' => $this->ceilingCents,
            'floor_cents' => $this->floorCents,
            'base' => $this->base,
            'exempt_non_nationals' => $this->exemptNonNationals,
            'min_service_days' => $this->minServiceDays,
            'legal_reference' => $this->legalReference,
            'effective_from' => $this->effectiveFrom,
            'source' => $this->source,
            'rule_public_id' => $this->rulePublicId,
        ];
    }
}
