<?php

declare(strict_types=1);

namespace Tests\Accuracy\Leave;

use InvalidArgumentException;

/**
 * Loads the leave golden cases (tests/Accuracy/Leave/cases/*.php, data only)
 * and checks their shape. It implements no rule and reads no engine.
 *
 * A case is typed by hand from docs/LEAVE-RULES.md before the engine is asked
 * (see docs/production testing/12-ATTENDANCE-ACCURACY.md for the method).
 * Every leave quantity in a case is an integer in tenths of a day; a count of
 * charged days is a plain integer.
 */
final class LeaveCases
{
    /** The family a case belongs to is the middle part of its id. */
    public const FAMILIES = ['DAYS', 'ENT', 'ACC', 'CARRY', 'BAL', 'REQ', 'RECALL', 'SICK', 'YEAR', 'PAYDAYS'];

    /**
     * What a case that does not say otherwise has. The default type has a
     * neutral code, so a case does not lean on a statutory value (annual,
     * maternity, paternity, sick) it was not written about; the type's own
     * values carry the arithmetic.
     */
    public const DEFAULTS = [
        'org' => ['leave_year' => 'gregorian', 'working_week' => [1, 2, 3, 4, 5], 'holidays' => []],
        'type' => [
            'code' => 'acc_days', 'default_days' => 16, 'accrual' => 'monthly',
            'seniority_every_years' => 0, 'seniority_extra_days' => 0,
            'carry_forward' => false, 'max_carry_days' => null, 'counts_calendar_days' => false,
            'is_paid' => true, 'follows_art85' => false, 'gender' => null,
            'max_consecutive_days' => null, 'min_notice_days' => 0,
        ],
        'employee' => [
            'hire_date' => '2020-01-01', 'termination_date' => null, 'gender' => 'male',
            'shift' => null, 'rotation' => null, 'assignments' => null,
        ],
        'holiday' => ['branch' => null, 'recurring' => false, 'active' => true, 'ethiopian' => false],
        'now' => '2026-12-01 09:00',
    ];

    private const KEYS = [
        'id', 'rule', 'source', 'working', 'undecided', 'signed', 'waits_on', 'readings', 'ruling',
        'org', 'type', 'employee', 'balances', 'requests', 'act', 'now', 'expect',
        'span', 'as_of', 'date', 'pay_period',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $all = null;

    /**
     * Every case, merged over the defaults, by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $cases = [];
        foreach (glob(__DIR__.'/cases/*.php') ?: [] as $file) {
            foreach (self::load($file) as $case) {
                self::validate($case, $file);
                if (isset($cases[$case['id']])) {
                    throw new InvalidArgumentException("Duplicate leave case id {$case['id']}");
                }
                $cases[$case['id']] = self::merged($case);
            }
        }
        ksort($cases);

        return self::$all = $cases;
    }

    /**
     * The Pest dataset: id => [case]. LEAVE_CASE=GL-ACC-001,GL-ACC-002 runs
     * only those (PHPUnit's --filter cannot see a dataset key).
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function dataset(): array
    {
        $only = array_filter(array_map('trim', explode(',', (string) getenv('LEAVE_CASE'))));
        $all = self::all();
        if ($only !== []) {
            $all = array_intersect_key($all, array_flip($only));
        }

        return array_map(fn (array $case): array => [$case], $all);
    }

    /**
     * A case file in a scope of its own: the files name their helpers $c, $d,
     * $file and so on, and must not overwrite this class's variables.
     *
     * @return list<array<string, mixed>>
     */
    private static function load(string $path): array
    {
        return (static fn (): array => require $path)();
    }

    /** @return array<string, string> case id => what the engine answered */
    public static function findings(): array
    {
        return require __DIR__.'/findings.php';
    }

    /**
     * A case for a property: the defaults with the given parts changed. It is
     * not a golden case and carries no expected value.
     *
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    public static function synthetic(array $parts): array
    {
        return self::merged(['id' => 'GL-DAYS-000', 'rule' => [], 'source' => 'property', 'working' => [], 'expect' => [], ...$parts]);
    }

    public static function family(string $id): string
    {
        return explode('-', $id)[1];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private static function merged(array $case): array
    {
        $case['org'] = [...self::DEFAULTS['org'], ...($case['org'] ?? [])];
        $case['type'] = [...self::DEFAULTS['type'], ...($case['type'] ?? [])];
        $case['employee'] = [...self::DEFAULTS['employee'], ...($case['employee'] ?? [])];
        $case['org']['holidays'] = array_map(fn (array $h): array => [...self::DEFAULTS['holiday'], ...$h], $case['org']['holidays']);
        $case['now'] ??= self::DEFAULTS['now'];
        $case['balances'] ??= [];
        $case['requests'] ??= [];
        $case['act'] ??= [];

        return $case;
    }

    /**
     * The shape every case must have, so a typing slip is an error here and
     * not a silent pass. Checked when the dataset is built.
     *
     * @param  array<string, mixed>  $case
     */
    private static function validate(array $case, string $file): void
    {
        $id = $case['id'] ?? '(no id)';
        $fail = fn (string $why): never => throw new InvalidArgumentException("Leave case {$id} in ".basename($file).": {$why}");

        if (preg_match('/^GL-('.implode('|', self::FAMILIES).')-\d{3}$/', (string) $id) !== 1) {
            $fail('the id must look like GL-<FAMILY>-NNN');
        }
        foreach (array_diff(array_keys($case), self::KEYS) as $key) {
            $fail("unknown key {$key}");
        }
        foreach (['rule', 'source', 'working', 'expect'] as $key) {
            if (empty($case[$key])) {
                $fail("{$key} is required");
            }
        }
        foreach ($case['rule'] as $rule) {
            if (preg_match('/^LVR-[A-Z]+-\d{2}$/', (string) $rule) !== 1) {
                $fail("rule {$rule} is not a register id");
            }
        }
        foreach ($case['working'] as $line) {
            if (! is_string($line)) {
                $fail('working holds strings');
            }
        }
        if (array_key_exists('signed', $case) && $case['signed'] !== null) {
            if (! isset($case['waits_on'])) {
                $fail('a signed case names the item it waited on');
            }
            if (! is_string($case['signed']) || trim($case['signed']) === '') {
                $fail('signed is null or "<date>, <role>, sign-off row <n>"');
            }
        }
        if (array_key_exists('signed', $case) && ! isset($case['waits_on'])) {
            $fail('signed without waits_on');
        }
        if (isset($case['waits_on']) && ! array_key_exists('signed', $case)) {
            $fail('waits_on without signed (use signed => null)');
        }
        if (($case['undecided'] ?? false) && isset($case['waits_on'])) {
            $fail('a case is undecided or waiting for a signature, not both');
        }

        $family = self::family((string) $id);
        $need = match ($family) {
            'DAYS' => ['span'],
            'ENT' => ['as_of'],
            'YEAR' => ['date'],
            'PAYDAYS' => ['pay_period'],
            default => [],
        };
        foreach ($need as $key) {
            if (! isset($case[$key])) {
                $fail("a {$family} case needs {$key}");
            }
        }

        $expect = $case['expect'];
        $allowed = match ($family) {
            'DAYS' => ['days', 'dates'],
            'ENT' => ['entitlement_tenths'],
            'YEAR' => ['year', 'month', 'starts', 'month_end'],
            'PAYDAYS' => ['unpaid_thousandths'],
            'SICK' => ['tiers', 'after'],
            default => ['after', 'initial'],
        };
        foreach (array_diff(array_keys($expect), $allowed) as $key) {
            $fail("expect.{$key} is not a {$family} field");
        }
        if (in_array($family, ['ACC', 'CARRY', 'BAL', 'REQ', 'RECALL', 'SICK'], true)) {
            $after = $expect['after'] ?? [];
            if (count($after) !== count($case['act'] ?? [])) {
                $fail('expect.after needs one entry per act');
            }
        }
        foreach ($case['act'] ?? [] as $act) {
            if (! in_array($act['do'] ?? null, ['accrue', 'carry', 'terminate', 'file', 'approve', 'reject', 'cancel', 'recall', 'add_holiday', 'roll_forward'], true)) {
                $fail('unknown act '.json_encode($act));
            }
        }
    }
}
