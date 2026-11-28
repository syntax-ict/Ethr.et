<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

/**
 * Loads and validates the payroll case files (cases/*.php in this directory
 * only). A case is data typed by hand from the rule register; this class only
 * checks its shape, so a mistyped field fails loudly instead of passing unseen
 * (spec 1.1, 1.2, 1.3, 1.7).
 *
 * Every helper is a static method: Pest loads all test files into one process.
 */
final class PayrollCases
{
    private const TOP = ['id', 'rule', 'source', 'undecided', 'run', 'period', 'org', 'employees', 'after_approval', 'expect', 'working'];

    private const PERIOD = ['start', 'end', 'pagume_days', 'label'];

    private const ORG = ['organization_type', 'pension_scheme', 'working_days_per_month', 'pagume', 'lateness_deductions', 'overtime_rates_x100', 'allowances'];

    private const ALLOWANCE = ['type', 'amount_cents', 'percent_x100', 'taxable'];

    private const EMPLOYEE = ['status', 'hire_date', 'termination_date', 'deleted', 'nationality', 'domestic_worker', 'salary_cents', 'salary_history', 'contracts', 'inputs', 'loans', 'cost_sharing'];

    private const INPUTS = ['unpaid_leave_thousandths', 'sick_days', 'overtime_minutes', 'lateness_minutes'];

    private const STATUSES = ['hired', 'probation', 'confirmed', 'resigned', 'terminated', 'retired', 'suspended'];

    /** The first date a case may start on: the first pension rule (the tax ladders start earlier). */
    public const EARLIEST_PERIOD = '2022-03-18';

    /** @return list<string> absolute paths */
    public static function files(): array
    {
        $files = glob(__DIR__.'/cases/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Every case of every file, in file order, with no de-duplication and no
     * filter: the format test needs to see a repeated id.
     *
     * @return list<array<string, mixed>>
     */
    public static function raw(): array
    {
        $cases = [];
        foreach (self::files() as $file) {
            foreach (require $file as $case) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * id => case, sorted. PAYROLL_CASE=GP-TAX-001,GP-NET-001 keeps only those
     * (PHPUnit's --filter cannot see a dataset key).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::raw() as $case) {
            $cases[(string) ($case['id'] ?? '')] = $case;
        }
        ksort($cases);

        $only = array_filter(array_map('trim', explode(',', (string) getenv('PAYROLL_CASE'))));

        return $only === [] ? $cases : array_intersect_key($cases, array_flip($only));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function dataset(): array
    {
        return array_map(static fn (array $case): array => [$case], self::all());
    }

    /**
     * The case without its answers: what the oracle is allowed to see.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    public static function question(array $case): array
    {
        unset($case['expect'], $case['working']);

        return $case;
    }

    /** @return array<string, mixed> */
    public static function statutory(): array
    {
        return require __DIR__.'/fixtures/statutory.php';
    }

    /** @return array<string, string> case id => what the engine answered */
    public static function findings(): array
    {
        return require __DIR__.'/findings.php';
    }

    /** @return array<string, string> case id => what the oracle answered */
    public static function oracleFindings(): array
    {
        return require __DIR__.'/oracle-findings.php';
    }

    /**
     * Everything wrong with the shape of one case; empty when it is as written.
     *
     * @param  array<string, mixed>  $case
     * @return list<string>
     */
    public static function validate(array $case): array
    {
        $id = is_string($case['id'] ?? null) ? $case['id'] : '(no id)';
        $errors = [];
        $err = static function (string $message) use (&$errors, $id): void {
            $errors[] = "[{$id}] {$message}";
        };

        foreach (self::floatPaths($case) as $path) {
            $err("a float at {$path}: every figure is an integer, every rate an integer with its scale in the key");
        }

        foreach (array_diff(array_keys($case), self::TOP) as $key) {
            $err("unknown key '{$key}'");
        }
        foreach (['id', 'rule', 'source', 'period', 'employees', 'expect', 'working'] as $key) {
            if (! array_key_exists($key, $case)) {
                $err("missing '{$key}'");
            }
        }

        if (! is_string($case['id'] ?? null) || preg_match('/^GP-[A-Z0-9]+-\d{3}$/', $case['id']) !== 1) {
            $err('id must look like GP-<THEME>-NNN');
        }

        $rules = $case['rule'] ?? null;
        if (! is_array($rules) || $rules === [] || ! array_is_list($rules)) {
            $err("'rule' must be a non-empty list of rule ids");
        } else {
            foreach ($rules as $rule) {
                if (! is_string($rule) || preg_match('/^PYR-[A-Z0-9]+-\d{2,3}$/', $rule) !== 1) {
                    $err('rule id '.json_encode($rule).' is not of the form PYR-XXX-NN');
                }
            }
        }

        if (isset($case['source']) && (! is_string($case['source']) || trim($case['source']) === '')) {
            $err("'source' must be a non-empty string");
        }
        if (array_key_exists('undecided', $case) && ! is_bool($case['undecided'])) {
            $err("'undecided' must be true or false");
        }

        $run = $case['run'] ?? 'preview';
        if (! in_array($run, ['preview', 'approve'], true)) {
            $err("'run' must be 'preview' or 'approve'");
        }

        $period = is_array($case['period'] ?? null) ? $case['period'] : [];
        self::validatePeriod($period, $err);

        $org = is_array($case['org'] ?? null) ? $case['org'] : [];
        if (array_key_exists('org', $case) && ! is_array($case['org'])) {
            $err("'org' must be an array");
        }
        self::validateOrg($org, $err);

        $employees = is_array($case['employees'] ?? null) ? $case['employees'] : [];
        if ($employees === []) {
            $err('at least one employee is needed');
        }
        foreach ($employees as $key => $employee) {
            if (! is_string($key) || preg_match('/^[A-Z][A-Z0-9-]*$/', $key) !== 1) {
                $err('employee key '.json_encode($key).' must match [A-Z][A-Z0-9-]*');

                continue;
            }
            self::validateEmployee($key, is_array($employee) ? $employee : [], $period, $err);
        }

        $afterApproval = $case['after_approval'] ?? null;
        if ($afterApproval !== null) {
            if ($run !== 'approve') {
                $err("'after_approval' is only for run = approve");
            }
            if (! is_array($afterApproval) || array_diff(array_keys($afterApproval), ['delete']) !== []) {
                $err("'after_approval' may only hold 'delete'");
            } else {
                foreach ((array) ($afterApproval['delete'] ?? []) as $key) {
                    if (! array_key_exists($key, $employees)) {
                        $err("after_approval.delete names unknown employee '{$key}'");
                    }
                }
            }
        }

        self::validateExpect($case, $run === 'approve', $org, $employees, $err);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  callable(string): void  $err
     */
    private static function validatePeriod(array $period, callable $err): void
    {
        foreach (array_diff(array_keys($period), self::PERIOD) as $key) {
            $err("period: unknown key '{$key}'");
        }
        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;
        foreach (['start' => $start, 'end' => $end] as $name => $value) {
            if (! self::isDate($value)) {
                $err("period.{$name} must be a valid Y-m-d date");
            }
        }
        if (self::isDate($start) && self::isDate($end)) {
            if (strcmp((string) $end, (string) $start) <= 0) {
                $err('period.end must be after period.start');
            }
            if (strcmp((string) $start, self::EARLIEST_PERIOD) < 0) {
                $err('period.start must be on or after '.self::EARLIEST_PERIOD.' (the first pension rule)');
            }
        }
        if (! is_int($period['pagume_days'] ?? null) || $period['pagume_days'] < 0) {
            $err('period.pagume_days is required: a whole number of days, 0 when the period touches no Pagume day');
        }
        if (isset($period['label']) && ! is_string($period['label'])) {
            $err('period.label must be text');
        }
    }

    /**
     * @param  array<string, mixed>  $org
     * @param  callable(string): void  $err
     */
    private static function validateOrg(array $org, callable $err): void
    {
        foreach (array_diff(array_keys($org), self::ORG) as $key) {
            $err("org: unknown key '{$key}'");
        }
        if (isset($org['organization_type']) && (! is_string($org['organization_type']) || preg_match('/^[a-z_]+$/', $org['organization_type']) !== 1)) {
            $err('org.organization_type must be an organisation kind code');
        }
        if (array_key_exists('pension_scheme', $org) && $org['pension_scheme'] !== null && ! in_array($org['pension_scheme'], ['private', 'public', 'public_military'], true)) {
            $err("org.pension_scheme must be null, 'private', 'public' or 'public_military'");
        }
        if (isset($org['working_days_per_month']) && (! is_int($org['working_days_per_month']) || $org['working_days_per_month'] < 20 || $org['working_days_per_month'] > 31)) {
            $err('org.working_days_per_month must be a whole number from 20 to 31');
        }
        if (isset($org['pagume']) && ! in_array($org['pagume'], ['full_month', 'daily_rate'], true)) {
            $err("org.pagume must be 'full_month' or 'daily_rate'");
        }
        if (isset($org['lateness_deductions']) && ! is_bool($org['lateness_deductions'])) {
            $err('org.lateness_deductions must be true or false');
        }
        foreach ((array) ($org['overtime_rates_x100'] ?? []) as $kind => $rate) {
            if (! in_array($kind, PayrollFields::OVERTIME_KINDS, true) || ! is_int($rate) || $rate <= 0) {
                $err('org.overtime_rates_x100: '.json_encode($kind).' must be an overtime kind with a positive whole-hundredths rate');
            }
        }
        foreach ((array) ($org['allowances'] ?? []) as $key => $allowance) {
            if (! is_string($key) || $key === '' || str_contains($key, '.')) {
                $err('org.allowances key '.json_encode($key).' must be a name without a dot');

                continue;
            }
            if (! is_array($allowance)) {
                $err("org.allowances.{$key} must be an array");

                continue;
            }
            foreach (array_diff(array_keys($allowance), self::ALLOWANCE) as $unknown) {
                $err("org.allowances.{$key}: unknown key '{$unknown}'");
            }
            $type = $allowance['type'] ?? null;
            if (! in_array($type, ['fixed', 'percentage'], true)) {
                $err("org.allowances.{$key}.type must be 'fixed' or 'percentage'");
            } elseif ($type === 'fixed' && ! is_int($allowance['amount_cents'] ?? null)) {
                $err("org.allowances.{$key}: a fixed allowance needs an integer amount_cents");
            } elseif ($type === 'percentage' && ! is_int($allowance['percent_x100'] ?? null)) {
                $err("org.allowances.{$key}: a percentage allowance needs an integer percent_x100");
            }
            if (! is_bool($allowance['taxable'] ?? null)) {
                $err("org.allowances.{$key}.taxable must be true or false");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $period
     * @param  callable(string): void  $err
     */
    private static function validateEmployee(string $key, array $employee, array $period, callable $err): void
    {
        foreach (array_diff(array_keys($employee), self::EMPLOYEE) as $unknown) {
            $err("employee {$key}: unknown key '{$unknown}'");
        }
        if (! in_array($employee['status'] ?? null, self::STATUSES, true)) {
            $err("employee {$key}: status must be one of ".implode(', ', self::STATUSES));
        }
        if (! self::isDate($employee['hire_date'] ?? null)) {
            $err("employee {$key}: hire_date must be a valid Y-m-d date");
        }
        if (($employee['termination_date'] ?? null) !== null && ! self::isDate($employee['termination_date'])) {
            $err("employee {$key}: termination_date must be a date or null");
        }
        if (isset($employee['deleted']) && ! is_bool($employee['deleted'])) {
            $err("employee {$key}: deleted must be true or false");
        }
        if (isset($employee['nationality']) && ! in_array($employee['nationality'], ['ethiopian', 'ethiopian_origin', 'foreign'], true)) {
            $err("employee {$key}: nationality must be ethiopian, ethiopian_origin or foreign");
        }
        if (isset($employee['domestic_worker']) && ! is_bool($employee['domestic_worker'])) {
            $err("employee {$key}: domestic_worker must be true or false");
        }
        if (! is_int($employee['salary_cents'] ?? null)) {
            $err("employee {$key}: salary_cents must be an integer");
        }

        foreach ((array) ($employee['salary_history'] ?? []) as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['effective', 'cents']) !== [] || ! self::isDate($row['effective'] ?? null) || ! is_int($row['cents'] ?? null)) {
                $err("employee {$key}: each salary_history row is ['effective' => Y-m-d, 'cents' => int]");
            }
        }
        foreach ((array) ($employee['contracts'] ?? []) as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['type', 'start', 'end', 'ended_at', 'salary_cents']) !== [] || ! is_string($row['type'] ?? null) || ! self::isDate($row['start'] ?? null) || ! is_int($row['salary_cents'] ?? null)) {
                $err("employee {$key}: each contract is ['type', 'start', 'end', 'ended_at', 'salary_cents']");
            }
        }

        $inputs = is_array($employee['inputs'] ?? null) ? $employee['inputs'] : [];
        foreach (array_diff(array_keys($inputs), self::INPUTS) as $unknown) {
            $err("employee {$key}: inputs: unknown key '{$unknown}'");
        }
        $thousandths = $inputs['unpaid_leave_thousandths'] ?? 0;
        if (! is_int($thousandths) || $thousandths < 0 || $thousandths % 100 !== 0) {
            $err("employee {$key}: unpaid_leave_thousandths must be a multiple of 100");
        }
        foreach ((array) ($inputs['sick_days'] ?? []) as $tier => $days) {
            if (! in_array($tier, ['full', 'half', 'unpaid'], true) || ! is_int($days) || $days < 0) {
                $err("employee {$key}: sick_days.".json_encode($tier).' must be full, half or unpaid, a whole number of days');
            }
        }
        foreach ((array) ($inputs['overtime_minutes'] ?? []) as $kind => $minutes) {
            if (! in_array($kind, PayrollFields::OVERTIME_KINDS, true) || ! is_int($minutes) || $minutes < 0) {
                $err("employee {$key}: overtime_minutes.".json_encode($kind).' must be an overtime kind, whole minutes');
            }
        }
        if (isset($inputs['lateness_minutes']) && (! is_int($inputs['lateness_minutes']) || $inputs['lateness_minutes'] < 0)) {
            $err("employee {$key}: lateness_minutes must be whole minutes");
        }

        foreach ((array) ($employee['loans'] ?? []) as $loanKey => $loan) {
            if (! is_string($loanKey) || preg_match('/^L\d+$/', $loanKey) !== 1) {
                $err("employee {$key}: loan key ".json_encode($loanKey).' must be L1, L2 ...');
            }
            if (! is_array($loan) || array_diff(array_keys($loan), ['instalment_cents', 'remaining_cents', 'status']) !== []
                || ! is_int($loan['instalment_cents'] ?? null) || ! is_int($loan['remaining_cents'] ?? null)
                || ! in_array($loan['status'] ?? null, ['active', 'completed', 'cancelled'], true)) {
                $err("employee {$key}: loan {$loanKey} is ['instalment_cents', 'remaining_cents', 'status' active|completed|cancelled]");
            }
        }
        foreach ((array) ($employee['cost_sharing'] ?? []) as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['rate_pct_x1000', 'outstanding_cents', 'status', 'started_on']) !== []
                || ! is_int($row['rate_pct_x1000'] ?? null) || ! is_int($row['outstanding_cents'] ?? null)
                || ! in_array($row['status'] ?? null, ['active', 'suspended', 'completed', 'cancelled'], true) || ! self::isDate($row['started_on'] ?? null)) {
                $err("employee {$key}: each cost_sharing row is ['rate_pct_x1000', 'outstanding_cents', 'status', 'started_on']");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $org
     * @param  array<string, mixed>  $employees
     * @param  callable(string): void  $err
     */
    private static function validateExpect(array $case, bool $approve, array $org, array $employees, callable $err): void
    {
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        $working = is_array($case['working'] ?? null) ? $case['working'] : [];
        if (array_key_exists('expect', $case) && $expect === []) {
            $err("'expect' must state at least one field");
        }
        $latenessOn = (bool) ($org['lateness_deductions'] ?? false);
        $allowances = is_array($org['allowances'] ?? null) ? $org['allowances'] : [];

        foreach ($expect as $who => $fields) {
            if (! is_array($fields) || $fields === []) {
                $err("expect.{$who} must state at least one field");

                continue;
            }
            $lines = self::workingLines($working[$who] ?? null);
            if (! array_key_exists($who, $working)) {
                $err("working.{$who} is missing");
            }

            if ($who === 'run') {
                if (! $approve) {
                    $err("expect.run is only for run = 'approve'");
                }
                foreach ($fields as $field => $value) {
                    if (! array_key_exists($field, PayrollFields::run())) {
                        $err("expect.run: unknown field '{$field}'");

                        continue;
                    }
                    self::validateValue("expect.run.{$field}", $value, $err);
                    if (! self::hasLine($lines, (string) $field)) {
                        $err("working.run has no line starting '{$field} = '");
                    }
                }

                continue;
            }

            if (! array_key_exists($who, $employees)) {
                $err("expect names employee '{$who}', who is not in 'employees'");

                continue;
            }
            $loans = is_array($employees[$who]['loans'] ?? null) ? $employees[$who]['loans'] : [];

            if (($fields['in_run'] ?? null) === 0 && count($fields) > 1) {
                $err("expect.{$who}: in_run is 0, so no other field may be stated");
            }

            foreach ($fields as $field => $value) {
                $field = (string) $field;
                $class = PayrollFields::classify($field);
                if ($class === null) {
                    $err("expect.{$who}: unknown field '{$field}'");

                    continue;
                }
                self::validateValue("expect.{$who}.{$field}", $value, $err);
                if (in_array($field, PayrollFields::LATENESS, true) && ! $latenessOn) {
                    $err("expect.{$who}.{$field}: lateness deductions are off, so state only lateness_step_logged = 0");
                }
                if ($field === 'lateness_step_logged' && $value !== ($latenessOn ? 1 : 0)) {
                    $err("expect.{$who}.lateness_step_logged must be ".($latenessOn ? 1 : 0).' for this organisation');
                }
                if (PayrollFields::needsApproval($field) && ! $approve) {
                    $err("expect.{$who}.{$field} is only for run = 'approve'");
                }
                if ($class['kind'] === 'allowance' && ! array_key_exists($class['key'], $allowances)) {
                    $err("expect.{$who}.{$field}: '{$class['key']}' is not in org.allowances");
                }
                if (str_starts_with($class['kind'], 'loan') && ! array_key_exists($class['key'], $loans)) {
                    $err("expect.{$who}.{$field}: '{$class['key']}' is not one of this employee's loans");
                }
                if (! self::hasLine($lines, $field)) {
                    $err("working.{$who} has no line starting '{$field} = '");
                }
            }
        }
    }

    /**
     * @param  callable(string): void  $err
     */
    private static function validateValue(string $where, mixed $value, callable $err): void
    {
        if (! is_int($value)) {
            $err("{$where} must be an integer");
        }
    }

    /** @return list<string> */
    private static function workingLines(mixed $lines): array
    {
        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    /** @param list<string> $lines */
    private static function hasLine(array $lines, string $field): bool
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, $field.' = ')) {
                return true;
            }
        }

        return false;
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @return list<string>
     */
    private static function floatPaths(mixed $value, string $path = ''): array
    {
        if (is_float($value)) {
            return [$path === '' ? '(root)' : $path];
        }
        if (! is_array($value)) {
            return [];
        }
        $found = [];
        foreach ($value as $key => $item) {
            $found = [...$found, ...self::floatPaths($item, $path === '' ? (string) $key : $path.'.'.$key)];
        }

        return $found;
    }
}
