<?php

declare(strict_types=1);

/**
 * One employee record per person rests on one rule: an employee is made only
 * by `App\Services\Identity\EmployeeIntake`, which first asks whether the
 * organisation already has them.
 *
 * Six paths made employees before 2026-11-11 and two of them (SCIM and SSO)
 * called `Employee::create()` without asking anything. A seventh path written
 * next year would do the same and nothing would say so: a duplicate employee
 * is not an error, it is two rows, and it is found months later as attendance
 * on one record and pay on the other.
 *
 * So this scans `app/` and fails when an employee is created anywhere but:
 *
 *  - `Services/EmployeeNumbering.php`, the layer under the gateway that gives
 *    the record its number, and
 *  - `Services/Identity/EmployeeIntake.php`, the only caller of that layer's
 *    creating methods.
 *
 * Seeders and factories live outside `app/` and are not scanned: they build
 * fixtures, not people.
 *
 * Tokenised, for the reason `TenantScopeBypassInventoryTest` gives: a regex
 * cannot tell `Employee::create(` from the same words in a comment that
 * explains why it is not called here.
 *
 * **What this does not prove.** It finds the constructs it knows. A record
 * made through a relation it has not heard of, or a raw statement built from a
 * variable table name, passes. `employees.created_via` is the other half: the
 * gateway writes it, so a record made after 2026-11-11 with none did not come
 * through it.
 */

/** Static and builder methods that write a new row. */
const EMPLOYEE_CREATORS = [
    'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'createOrFirst',
    'firstOrCreate', 'updateOrCreate', 'firstOrNew', 'make', 'createMany', 'save', 'saveMany',
    'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'updateOrInsert',
];

/** EmployeeNumbering's methods that end in a new employee. */
const EMPLOYEE_NUMBERING_CREATORS = ['create', 'createFromPool', 'assign'];

/**
 * Every employee-creating construct in one file's source, as labels.
 *
 * @return list<string>
 */
function employeeCreationSites(string $source): array
{
    // Comments and whitespace out, so "the next token" means the next token.
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $total = count($tokens);

    $text = static fn (int $i): ?string => $i < 0 || $i >= $total ? null : (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
    $isName = static fn (int $i): bool => $i >= 0 && $i < $total && is_array($tokens[$i])
        && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    $isEmployee = static fn (int $i): bool => $isName($i) && (bool) preg_match('/(^|\\\\)Employee$/', (string) $text($i));

    /**
     * Method names called on the chain that starts at $from, up to the end of
     * the statement. Only at the chain's own depth: a closure passed to one of
     * its calls is somebody else's statement.
     */
    $chain = static function (int $from) use ($tokens, $total, $text): array {
        $called = [];
        $depth = 0;
        for ($i = $from; $i < $total; $i++) {
            $t = $text($i);
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                if (--$depth < 0) {
                    break;
                }
            } elseif ($depth === 0 && ($t === ';' || $t === ',')) {
                break;
            } elseif ($depth === 0 && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING
                && in_array($text($i - 1), ['::', '->', '?->'], true) && $text($i + 1) === '(') {
                $called[] = $t;
            }
        }

        return $called;
    };

    $sites = [];

    for ($i = 0; $i < $total; $i++) {
        // new Employee
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW && $isEmployee($i + 1)) {
            $sites[] = 'new Employee';

            continue;
        }

        // Employee::create(...), Employee::query()->…->insert(...)
        if ($isEmployee($i) && $text($i + 1) === '::') {
            foreach (array_intersect($chain($i + 1), EMPLOYEE_CREATORS) as $method) {
                $sites[] = "Employee::{$method}";
            }

            continue;
        }

        // $tenant->employees()->create(...)
        if ($text($i) === 'employees' && $text($i + 1) === '(' && $text($i + 2) === ')' && in_array($text($i - 1), ['->', '?->'], true)) {
            foreach (array_intersect($chain($i + 3), EMPLOYEE_CREATORS) as $method) {
                $sites[] = "employees()->{$method}";
            }

            continue;
        }

        // DB::table('employees')->insert(...)
        if ($text($i) === 'table' && $text($i + 1) === '(' && in_array($text($i + 2), ["'employees'", '"employees"'], true) && $text($i + 3) === ')') {
            foreach (array_intersect($chain($i + 4), EMPLOYEE_CREATORS) as $method) {
                $sites[] = "table('employees')->{$method}";
            }

            continue;
        }

        // $numbering->create(...), $this->numbering->createFromPool(...)
        if (in_array($text($i), ['$numbering', 'numbering'], true) && $text($i + 1) === '->'
            && in_array($text($i + 2), EMPLOYEE_NUMBERING_CREATORS, true) && $text($i + 3) === '(') {
            $sites[] = 'numbering->'.$text($i + 2);
        }
    }

    return $sites;
}

/** `->replicate(` calls in one file's source: a copy of a row is a new row. */
function replicateCalls(string $source): int
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $count = 0;
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'replicate' && ($tokens[$i + 1] ?? null) === '(') {
            $count++;
        }
    }

    return $count;
}

/**
 * @return array<string, string> relative path => source, for every PHP file under app/
 */
function appSources(): array
{
    $appDir = base_path('app');
    $sources = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

it('recognises each way a record can be made, and ignores prose about them', function () {
    $found = fn (string $php): array => employeeCreationSites("<?php\n".$php);

    expect($found('Employee::create(["name" => "A"]);'))->toBe(['Employee::create'])
        ->and($found('\App\Models\Employee::forceCreate([]);'))->toBe(['Employee::forceCreate'])
        ->and($found('Employee::query()->where("a", 1)->firstOrCreate([]);'))->toBe(['Employee::firstOrCreate'])
        ->and($found('Employee::withTrashed()->updateOrCreate([], []);'))->toBe(['Employee::updateOrCreate'])
        ->and($found('$e = new Employee(["name" => "A"]);'))->toBe(['new Employee'])
        ->and($found('$tenant->employees()->create([]);'))->toBe(['employees()->create'])
        ->and($found('DB::table("employees")->insert([]);'))->toBe(["table('employees')->insert"])
        ->and($found("DB::table('employees')->where('id', 1)->insertOrIgnore([]);"))->toBe(["table('employees')->insertOrIgnore"])
        ->and($found('$this->numbering->create($tenant, []);'))->toBe(['numbering->create'])
        ->and($found('$numbering->createFromPool($tenant, [], $pool);'))->toBe(['numbering->createFromPool'])
        ->and($found('$numbering->assign($tenant, fn () => 1);'))->toBe(['numbering->assign']);

    // Reads, updates, other models, and the words in a comment or a string.
    expect($found('Employee::where("a", 1)->update(["name" => "B"]);'))->toBe([])
        ->and($found('Employee::query()->each(function ($e) { Shift::create([]); });'))->toBe([])
        ->and($found('EmployeeResource::make($employee);'))->toBe([])
        ->and($found('EmployeeDocument::create([]);'))->toBe([])
        ->and($found('// Employee::create() is not called here.'))->toBe([])
        ->and($found('$note = "Employee::create([])";'))->toBe([])
        ->and($found('DB::table("employees")->where("id", 1)->update([]);'))->toBe([])
        ->and($found('$numbering->next($tenant); $numbering->sequence($tenant, 3);'))->toBe([])
        ->and($found('$tenant->employees()->count();'))->toBe([]);
});

it('creates employees only inside the intake gateway and the numbering under it', function () {
    $sources = appSources();

    // A scan that silently read nothing would pass while checking nothing,
    // which is how `vendor/bin/pest` collected 21 of 132 classes and exited 0.
    expect(count($sources))->toBeGreaterThan(300)
        ->and($sources)->toHaveKey('Services/Identity/EmployeeIntake.php')
        ->and($sources)->toHaveKey('Http/Controllers/Api/V1/Employee/EmployeeController.php');

    $actual = [];
    foreach ($sources as $path => $source) {
        $sites = employeeCreationSites($source);
        if ($sites !== []) {
            sort($sites);
            $actual[$path] = array_values(array_unique($sites));
        }
    }

    expect($actual)->toBe([
        'Services/EmployeeNumbering.php' => ['Employee::create'],
        'Services/Identity/EmployeeIntake.php' => ['numbering->create', 'numbering->createFromPool'],
    ], 'An employee is created outside App\\Services\\Identity\\EmployeeIntake. '
        .'Present the person to the gateway (admit(), or ask() then create()) so it can ask whether '
        .'the organisation already has them. If the construct is not an employee at all, teach '
        .'employeeCreationSites() to tell the difference; do not add the file here.');
});

it('copies no employee: every replicate() call is on a record that is not a person', function () {
    $actual = [];
    foreach (appSources() as $path => $source) {
        $count = replicateCalls($source);
        if ($count > 0) {
            $actual[$path] = $count;
        }
    }

    // `replicate()` cannot be attributed to a model by its tokens, so the
    // call sites are pinned and each was read: a leave type, a shift, and an
    // attendance policy held in memory and never saved. A new one fails here
    // and is read the same way.
    expect($actual)->toBe([
        'Http/Controllers/Api/V1/Leave/LeaveTypeController.php' => 1,
        'Http/Controllers/Api/V1/Shift/ShiftController.php' => 1,
        'Services/Attendance/AttendancePolicy.php' => 1,
    ]);
});
