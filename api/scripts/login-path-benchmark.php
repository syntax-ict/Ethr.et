<?php

declare(strict_types=1);

/**
 * G0-J / risk #12 (BASELINE §13d) — measures whether AuthIdentifierResolver's
 * lookups need an index, against the real migrated schema (not a synthetic
 * table), on the project's actual MariaDB 10.4.32.
 *
 * Run with: php scripts/login-path-benchmark.php
 * Requires DB_DATABASE to contain "bench" (guarded below) so this can never be
 * pointed at a real database by accident — it truncates users/employees/
 * tenants in whatever it connects to.
 *
 * ## What this measures
 *
 * Each of email, username and employee_code follows the same shape: a
 * `whereRaw('LOWER(column) = ?', ...)` against a column that already carries a
 * `(tenant_id, column)` unique index. The LOWER() wrapper prevents MariaDB
 * from using that index, so the current query is bounded by tenant size
 * (O(n)); a plain equality would be O(1) via the existing index. Phone is
 * different — it needs `REPLACE()` for format normalisation, which no
 * existing index can serve regardless of LOWER().
 *
 * ## Why this script does not just fix AuthIdentifierResolver.php
 *
 * The obvious change — drop `LOWER()` from the column side, since the column
 * is already `utf8mb4_unicode_ci` (case-insensitive) — is correct on MySQL/
 * MariaDB but silently breaks case-insensitive login on SQLite, which the
 * primary test suite runs on. Verified directly:
 *
 *   SQLite, column value "Abc@X.com":
 *     WHERE email = 'abc@x.com'          -> 0 rows  (case-sensitive)
 *     WHERE LOWER(email) = 'abc@x.com'   -> 1 row   (works)
 *
 * SQLite has no equivalent of MySQL's collation-level case-folding for a bare
 * `=`; matching case-insensitively there requires either `LOWER()` in the
 * query or a `NOCASE` column collation applied specifically for that driver.
 * So a same-behavior-on-every-driver fix is a schema change (a
 * driver-conditional column collation), not a one-line code edit — which puts
 * it in the same risk class as adding an index, and it has not been made here
 * without that decision being made explicitly.
 */

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\AuthIdentifierResolver;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$dbName = DB::connection()->getDatabaseName();
if (! str_contains($dbName, 'bench')) {
    fwrite(STDERR, "Refusing to run against '{$dbName}' — expected a database with 'bench' in its name.\n");
    exit(1);
}

echo "Database: {$dbName}\n";
echo 'MariaDB: '.DB::selectOne('SELECT VERSION() AS v')->v."\n\n";

function ms(float $start): float
{
    return round((microtime(true) - $start) * 1000, 2);
}

/** @return array{tenant_id:int, emails:array<int,string>, phones:array<int,string>, codes:array<int,string>, usernames:array<int,string>} */
function seedTenant(int $n): array
{
    DB::table('users')->delete();
    DB::table('employees')->delete();
    DB::table('tenants')->delete();

    $tenant = Tenant::factory()->create(['subdomain' => 'bench'.$n]);

    $emails = [];
    $phones = [];
    $codes = [];
    $usernames = [];

    $now = now();
    $employeeRows = [];
    $userRows = [];

    // Phone formats deliberately messy, matching what a real HR admin types:
    // spaces, dashes, parens, a leading +251. REPLACE() strips exactly these.
    $phoneFormats = [
        fn (string $d) => "+251 {$d[0]}{$d[1]} {$d[2]}{$d[3]}{$d[4]} {$d[5]}{$d[6]}{$d[7]}{$d[8]}",
        fn (string $d) => "0{$d}",
        fn (string $d) => "({$d[0]}{$d[1]}) {$d[2]}{$d[3]}{$d[4]}-{$d[5]}{$d[6]}{$d[7]}{$d[8]}",
    ];

    for ($i = 1; $i <= $n; $i++) {
        $digits = str_pad((string) (900000000 + $i), 9, '0', STR_PAD_LEFT);
        $format = $phoneFormats[$i % 3];
        $phone = $format($digits);
        $email = "employee{$i}@bench{$n}.et";
        $code = 'EMP'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        $username = 'user'.$i;

        $employeeRows[] = [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'name' => "Employee {$i}",
            'employee_code' => $code,
            'phone' => $phone,
            'gender' => 'male',
            'date_of_birth' => '1990-01-01',
            'hire_date' => '2020-01-01',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // A fixed sample count (50) regardless of tenant size, so every tier's
        // average is over the same number of lookups and small-n runs are not
        // dominated by warm-up noise from a handful of samples.
        $sampleStride = max(1, (int) floor($n / 50));
        if ($i % $sampleStride === 0) {
            $emails[] = $email;
            $phones[] = $phone;
            $codes[] = $code;
            $usernames[] = $username;
        }
    }

    // Batch-insert employees, then users referencing them, in chunks.
    foreach (array_chunk($employeeRows, 1000) as $chunk) {
        DB::table('employees')->insert($chunk);
    }

    $employeeIds = DB::table('employees')->where('tenant_id', $tenant->id)
        ->orderBy('id')->pluck('id')->all();

    $i = 0;
    foreach ($employeeIds as $empId) {
        $i++;
        $digits = str_pad((string) (900000000 + $i), 9, '0', STR_PAD_LEFT);
        $format = $phoneFormats[$i % 3];
        $userRows[] = [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'employee_id' => $empId,
            'email' => "employee{$i}@bench{$n}.et",
            'username' => 'user'.$i,
            'phone' => $format($digits),
            'password' => 'x',
            'role' => 'employee',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($userRows, 1000) as $chunk) {
        DB::table('users')->insert($chunk);
    }

    return [
        'tenant_id' => $tenant->id,
        'emails' => $emails,
        'phones' => $phones,
        'codes' => $codes,
        'usernames' => $usernames,
    ];
}

/** Average wall-clock ms of $fn() over $reps calls with distinct inputs. */
function timeLookups(array $values, callable $fn): float
{
    // One discarded warm-up call (connection/statement-prep cost), then every
    // sampled value is used exactly once — distinct values, not the same row
    // repeated, so this cannot be flattered by MariaDB's query cache.
    $fn($values[0]);
    $reps = count($values) - 1;
    $picked = array_slice($values, 1);
    $start = microtime(true);
    foreach ($picked as $v) {
        $fn($v);
    }

    return round((microtime(true) - $start) * 1000 / $reps, 3);
}

$resolver = new AuthIdentifierResolver;
$sizes = [500, 5000, 20000];
$results = [];

foreach ($sizes as $n) {
    echo "=== Seeding {$n} employees/users (one tenant) ===\n";
    $t0 = microtime(true);
    $seed = seedTenant($n);
    echo 'Seed time: '.ms($t0)."ms\n";

    $tenantId = $seed['tenant_id'];
    app(CurrentTenant::class)->set(Tenant::find($tenantId));

    // --- current behaviour: LOWER(email) = ?, no usable index ---
    $emailCurrentMs = timeLookups($seed['emails'], fn ($v) => $resolver->resolve($tenantId, $v, ['email']));

    // --- candidate fix: plain equality, using the existing (tenant_id,email) unique index ---
    $emailFixedMs = timeLookups($seed['emails'], fn ($v) => User::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)->where('email', $v)->first());

    // --- username: same shape as email ---
    $usernameCurrentMs = timeLookups($seed['usernames'], fn ($v) => $resolver->resolve($tenantId, $v, ['username']));
    $usernameFixedMs = timeLookups($seed['usernames'], fn ($v) => User::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)->where('username', $v)->first());

    // --- employee_code: same shape ---
    $codeCurrentMs = timeLookups($seed['codes'], fn ($v) => $resolver->resolve($tenantId, $v, ['employee_code']));
    $codeFixedMs = timeLookups($seed['codes'], fn ($v) => Employee::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)->where('employee_code', $v)->first());

    // --- phone: REPLACE(...) chain, genuinely unindexable as written ---
    $phoneCurrentMs = timeLookups($seed['phones'], fn ($v) => $resolver->resolve($tenantId, preg_replace('/\D+/', '', $v), ['phone']));

    $results[$n] = compact(
        'emailCurrentMs', 'emailFixedMs',
        'usernameCurrentMs', 'usernameFixedMs',
        'codeCurrentMs', 'codeFixedMs',
        'phoneCurrentMs',
    );

    // EXPLAIN, captured once per size at the largest sample email.
    $sampleEmail = $seed['emails'][0];
    $explainCurrent = DB::select(
        'EXPLAIN SELECT * FROM users WHERE tenant_id = ? AND LOWER(email) = ?',
        [$tenantId, mb_strtolower($sampleEmail)]
    );
    $explainFixed = DB::select(
        'EXPLAIN SELECT * FROM users WHERE tenant_id = ? AND email = ?',
        [$tenantId, $sampleEmail]
    );
    echo "\nEXPLAIN current (LOWER(email)): type=".($explainCurrent[0]->type ?? '?')
        .' key='.($explainCurrent[0]->key ?? 'NULL')
        .' rows='.($explainCurrent[0]->rows ?? '?')."\n";
    echo 'EXPLAIN fixed (plain email):    type='.($explainFixed[0]->type ?? '?')
        .' key='.($explainFixed[0]->key ?? 'NULL')
        .' rows='.($explainFixed[0]->rows ?? '?')."\n\n";

    printf(
        "%-16s %10s %10s\n",
        'lookup', 'current(ms)', 'fixed(ms)'
    );
    printf("%-16s %10.3f %10.3f\n", 'email', $emailCurrentMs, $emailFixedMs);
    printf("%-16s %10.3f %10.3f\n", 'username', $usernameCurrentMs, $usernameFixedMs);
    printf("%-16s %10.3f %10.3f\n", 'employee_code', $codeCurrentMs, $codeFixedMs);
    printf("%-16s %10.3f %10s\n", 'phone', $phoneCurrentMs, 'n/a');
    echo "\n";
}

echo "=== Summary across sizes (average ms/lookup) ===\n";
printf("%-8s %14s %14s %14s %14s %14s %14s %14s\n",
    'n', 'email-cur', 'email-fix', 'user-cur', 'user-fix', 'code-cur', 'code-fix', 'phone-cur');
foreach ($results as $n => $r) {
    printf(
        "%-8d %14.3f %14.3f %14.3f %14.3f %14.3f %14.3f %14.3f\n",
        $n,
        $r['emailCurrentMs'], $r['emailFixedMs'],
        $r['usernameCurrentMs'], $r['usernameFixedMs'],
        $r['codeCurrentMs'], $r['codeFixedMs'],
        $r['phoneCurrentMs'],
    );
}
