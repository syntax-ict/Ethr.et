<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ContractType;
use App\Http\Requests\Employee\StoreBankDetailRequest;
use App\Http\Requests\Employee\StoreEmergencyContactRequest;
use App\Http\Requests\Employee\StoreEmployeeContractRequest;
use Closure;
use Illuminate\Support\Carbon;

/**
 * What a staged list says about the rest of a new person's profile: a grade,
 * a cost centre, a probation end, a contract, a bank account and an emergency
 * contact (EmployeeImportRow::PROFILE).
 *
 * The rules are the ones the screens that edit these records already state.
 * The bank account's come from StoreBankDetailRequest, the emergency contact's
 * from StoreEmergencyContactRequest and the contract's type from
 * StoreEmployeeContractRequest, each read from the request itself under the
 * import's column names, so a rule changed there changes here. A request
 * requires its whole record; a list may leave the whole group blank, so a
 * required field becomes required once another field of its group is given.
 *
 * What a rule cannot say, because it compares two columns or asks the
 * organisation, is in problems().
 */
final class EmployeeImportProfile
{
    /** Request field => the import column it is read from. */
    private const BANK = ['bank_name' => 'bank_name', 'branch_name' => 'bank_branch', 'account_number' => 'bank_account'];

    private const EMERGENCY = ['name' => 'emergency_name', 'relationship' => 'emergency_relationship', 'phone' => 'emergency_phone'];

    /** The columns that name something the organisation keeps, and which RelationMatch finds it. */
    public const RELATIONS = ['grade', 'cost_center'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $contract = (new StoreEmployeeContractRequest)->rules();

        return [
            $prefix.'grade' => ['nullable', 'string', 'max:255'],
            $prefix.'cost_center' => ['nullable', 'string', 'max:255'],
            $prefix.'probation_end_date' => ['nullable', 'date'],
            $prefix.'contract_type' => self::optional((array) $contract['contract_type']),
            $prefix.'contract_end_date' => self::optional(self::withoutComparison((array) ($contract['end_date'] ?? []))),
            ...self::group((new StoreBankDetailRequest)->rules(), self::BANK, $prefix),
            ...self::group((new StoreEmergencyContactRequest)->rules(), self::EMERGENCY, $prefix),
        ];
    }

    /**
     * What is wrong with a row that no single column's rule can say.
     *
     * `$row` is keyed by column, dates as Y-m-d. A row with no hire date is
     * brought in with today's (as the intake does), which only the probation
     * check uses: a contract starts on the hire date, so a row with a contract
     * and no hire date is refused. `$relation`
     * gives the organisation's grades or cost centres for the column it is
     * asked about; it is called only for a column the row fills.
     *
     * With `$number`, each message begins with the row it is about.
     *
     * @param  array<string, mixed>  $row
     * @param  Closure(string): RelationMatch  $relation
     * @return list<array{column: string, message: string}>
     */
    public static function problems(array $row, Closure $relation, ?int $number = null): array
    {
        $found = [];

        foreach (self::RELATIONS as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $match = $relation($column);
            if ($match->id($value) !== null) {
                continue;
            }

            $found[] = [$column, __(
                $match->ambiguous($value) ? 'onboarding.migration.relation_ambiguous' : 'onboarding.migration.relation_not_found',
                ['field' => __('employee.import_field_'.$column), 'value' => $value],
            )];
        }

        $hire = self::day($row['hire_date'] ?? null);
        $start = $hire ?? Carbon::today();
        $probationEnd = self::day($row['probation_end_date'] ?? null);

        if ($probationEnd !== null && $probationEnd->lt($start)) {
            $found[] = ['probation_end_date', __('onboarding.migration.profile_probation_before_hire')];
        }

        $type = ContractType::tryFrom((string) ($row['contract_type'] ?? ''));
        $given = isset($row['contract_type']) && trim((string) $row['contract_type']) !== '';
        $end = self::day($row['contract_end_date'] ?? null);
        $endGiven = isset($row['contract_end_date']) && trim((string) $row['contract_end_date']) !== '';

        if ($given && $hire === null) {
            $found[] = ['hire_date', __('onboarding.migration.profile_contract_needs_hire_date')];
        }
        if ($endGiven && ! $given) {
            $found[] = ['contract_type', __('onboarding.migration.profile_contract_type_needed')];
        }
        if ($type?->requiresEndDate() === true && ! $endGiven) {
            $found[] = ['contract_end_date', __('onboarding.migration.profile_contract_end_needed')];
        }
        if ($type !== null && $end !== null && $hire !== null && $end->lte($hire)) {
            $found[] = ['contract_end_date', __('onboarding.migration.profile_contract_end_before_start')];
        }

        return array_map(
            fn (array $problem): array => [
                'column' => $problem[0],
                'message' => $number === null ? $problem[1] : __('employee.import_row', ['row' => $number, 'message' => $problem[1]]),
            ],
            $found,
        );
    }

    /**
     * A record's rules under the import's column names, each field required
     * only when another field of the same record is given.
     *
     * @param  array<string, mixed>  $source  a request's rules
     * @param  array<string, string>  $map  request field => import column
     * @return array<string, list<mixed>>
     */
    private static function group(array $source, array $map, string $prefix): array
    {
        $rules = [];

        foreach ($map as $field => $column) {
            $others = array_map(
                fn (string $other): string => $prefix.$other,
                array_values(array_diff($map, [$column])),
            );

            $converted = array_map(
                fn (mixed $rule): mixed => $rule === 'required' ? 'required_with:'.implode(',', $others) : $rule,
                (array) $source[$field],
            );

            $rules[$prefix.$column] = self::optional($converted);
        }

        return $rules;
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function optional(array $rules): array
    {
        $rules = array_values(array_filter($rules, fn (mixed $rule): bool => $rule !== 'required'));

        return in_array('nullable', $rules, true) ? $rules : ['nullable', ...$rules];
    }

    /**
     * A date rule that compares with the request's other field, which a
     * column of a list does not have: problems() compares it with the hire date.
     *
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function withoutComparison(array $rules): array
    {
        return array_values(array_filter(
            $rules,
            fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'after'),
        ));
    }

    private static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
