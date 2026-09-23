<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\AuthIdentifierResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * `AuthIdentifierResolver` must reach every identifier through an index.
 *
 * Until 2026-09-23 each lookup wrapped the column in a SQL function — three
 * `LOWER()`s and a six-deep `REPLACE()` chain — and a predicate on a function
 * of a column cannot use an index on that column. Three of the four had a
 * composite unique index sitting right there that the query could not reach.
 * Every login therefore examined every row belonging to the tenant, on an
 * unauthenticated endpoint. `BASELINE.md` risk #12, closed in §15e.
 *
 * `LoginIdentifierTest` covers *which* user each identifier resolves to and is
 * deliberately untouched by that change — the behaviour is supposed to be
 * identical. This file covers the part that is not observable from the
 * response: that the database can still find the row without reading the rest.
 *
 * Read per driver, because "uses an index" is not one question:
 *
 *  - **SQLite** reports the index it chose, so this asserts the choice. With
 *    two equality columns against one it will not pick `tenant_id` alone.
 *  - **MariaDB** is asked for `possible_keys` — whether the index is a
 *    *candidate* — rather than `key`. The optimiser is free to table-scan a
 *    few rows however good the index is, so asserting its choice would pin the
 *    optimiser's cost model on a seeded test table instead of the schema.
 *    Candidacy is the property this migration actually changes: reintroduce a
 *    `LOWER()` and the normalised index stops being a candidate at all.
 */
function captureResolverQueries(callable $resolve): array
{
    $captured = [];

    DB::listen(function ($query) use (&$captured): void {
        $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $resolve();

    return $captured;
}

/**
 * The plan for the one captured query that touches `$column`, as a single
 * lower-cased string per driver.
 */
function identifierQueryPlan(array $queries, string $column): string
{
    $match = null;

    foreach ($queries as $query) {
        if (str_contains($query['sql'], $column)) {
            $match = $query;
            break;
        }
    }

    expect($match !== null)->toBeTrue("No query touching `{$column}` was issued at all.");

    if (DB::connection()->getDriverName() === 'sqlite') {
        $rows = DB::select('EXPLAIN QUERY PLAN '.$match['sql'], $match['bindings']);

        return mb_strtolower(implode(' ', array_column($rows, 'detail')));
    }

    $rows = DB::select('EXPLAIN '.$match['sql'], $match['bindings']);

    return mb_strtolower(implode(' ', array_map(
        static fn ($row): string => (string) ($row->possible_keys ?? ''),
        $rows,
    )));
}

describe('login identifier lookups are indexed', function () {
    it('reaches every identifier type through its normalised index', function (
        string $type,
        string $value,
        string $column,
        string $index,
    ) {
        $tenant = createTenant();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-900',
            'phone' => '0911 22 33 44',
        ]);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'email' => 'abebe@acme.test',
            'username' => 'abebe.k',
            'phone' => '0911 55 66 77',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        $queries = captureResolverQueries(fn () => app(AuthIdentifierResolver::class)
            ->resolve($tenant->id, $value, [$type]));

        expect(identifierQueryPlan($queries, $column))->toContain(mb_strtolower($index));
    })->with([
        'email' => ['email', 'ABEBE@Acme.test', 'email_normalized', 'users_tenant_id_email_normalized_index'],
        'username' => ['username', 'ABEBE.K', 'username_normalized', 'users_tenant_id_username_normalized_index'],
        'phone on the user' => ['phone', '0911 55-66-77', 'phone_normalized', 'users_tenant_id_phone_normalized_index'],
        'employee number' => ['employee_code', 'emp-900', 'employee_code_normalized', 'employees_tenant_id_employee_code_normalized_index'],
    ]);

    it('reaches the employee phone fallback through its index', function () {
        // A separate case because it is the resolver's *second* query: it runs
        // only when the user carries no phone of their own, which is the common
        // shape — the phone is on the employee record, not the login.
        $tenant = createTenant();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => '0911 22 33 44',
        ]);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'phone' => null,
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        $queries = captureResolverQueries(fn () => app(AuthIdentifierResolver::class)
            ->resolve($tenant->id, '0911223344', ['phone']));

        expect(identifierQueryPlan($queries, 'employees'))
            ->toContain('employees_tenant_id_phone_normalized_index');
    });

    it('never wraps an identifier column in a SQL function', function () {
        // The cheap, driver-independent half. `identifierQueryPlan()` above proves the
        // index is reachable today; this states the rule that keeps it
        // reachable, and names the mistake in its failure message rather than
        // leaving the next author to rediscover it from a plan dump.
        $tenant = createTenant();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-901',
            'phone' => '0911 22 33 45',
        ]);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'email' => 'kebede@acme.test',
            'username' => 'kebede.a',
            'phone' => '0911 55 66 78',
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        $resolver = app(AuthIdentifierResolver::class);

        $queries = captureResolverQueries(function () use ($resolver, $tenant): void {
            foreach (AuthIdentifierResolver::AVAILABLE as $type) {
                $resolver->resolve($tenant->id, 'no-such-identifier-0911', [$type]);
            }
        });

        foreach ($queries as $query) {
            $sql = mb_strtolower($query['sql']);

            expect($sql)->not->toContain('lower(');
            expect($sql)->not->toContain('replace(');
        }

        expect($queries)->not->toBeEmpty();
    });
});
