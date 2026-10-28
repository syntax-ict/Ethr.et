<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Exceptions\PayrollRunRefused;

/**
 * The statutory values in force for one organisation on one date, each with
 * where it came from. Built by StatutoryRules; written whole into every
 * payroll entry's calculation_log, so an approved run keeps saying which
 * rule it applied and from which row (convention #11).
 *
 * Asking for a value that has no row in force is refused by name rather than
 * answered with a default: a missing rule is a configuration fault for a
 * person to fix, not something to guess at.
 */
final class StatutoryRuleSet
{
    /**
     * @param  array<string, array{value: mixed, source: string, legal_reference: string|null, confirmed: bool, effective_from: string|null, parameter: string|null}>  $rules
     */
    public function __construct(
        public readonly ?string $organizationType,
        private readonly array $rules,
    ) {}

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->rules);
    }

    public function get(string $code): mixed
    {
        if (! $this->has($code)) {
            throw PayrollRunRefused::noRuleInForce($code);
        }

        return $this->rules[$code]['value'];
    }

    public function int(string $code): int
    {
        $value = $this->get($code);

        return is_numeric($value) ? (int) $value : throw PayrollRunRefused::noRuleInForce($code);
    }

    public function string(string $code): string
    {
        $value = $this->get($code);

        return is_string($value) ? $value : throw PayrollRunRefused::noRuleInForce($code);
    }

    /** @return array<array-key, mixed> */
    public function list(string $code): array
    {
        $value = $this->get($code);

        return is_array($value) ? $value : throw PayrollRunRefused::noRuleInForce($code);
    }

    /** @return array{value: mixed, source: string, legal_reference: string|null, confirmed: bool, effective_from: string|null, parameter: string|null}|null */
    public function describe(string $code): ?array
    {
        return $this->rules[$code] ?? null;
    }

    /** @return array{organization_type: string|null, rules: array<string, array{value: mixed, source: string, legal_reference: string|null, confirmed: bool, effective_from: string|null, parameter: string|null}>} */
    public function toLog(): array
    {
        return ['organization_type' => $this->organizationType, 'rules' => $this->rules];
    }
}
