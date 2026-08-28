<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Import\EmployeeImporter;
use Illuminate\Support\Facades\DB;

function nidResolver(): IdentityResolver
{
    return app(IdentityResolver::class);
}

describe('national ID storage', function () {
    it('encrypts the national ID at rest and stores only a hash for lookup', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'national_id' => 'ETH-123-456']);

        // Raw DB row: the value is ciphertext, the hash is a 64-char digest.
        $raw = DB::table('employees')->where('id', $employee->id)->first();

        expect($raw->national_id)->not->toBe('ETH-123-456');
        expect(strlen($raw->national_id_hash))->toBe(64);
        // The accessor still returns the plaintext to the app.
        expect($employee->fresh()->national_id)->toBe('ETH-123-456');
    });

    it('normalizes case and spacing so the hash is format-insensitive', function () {
        // Whitespace is stripped and the value upper-cased before hashing.
        expect(Employee::hashNationalId('eth 123 456'))->toBe(Employee::hashNationalId('ETH123456'));
        expect(Employee::hashNationalId('ETH123456'))->toBe(Employee::hashNationalId(' eth123456 '));
        expect(Employee::hashNationalId(null))->toBeNull();
        expect(Employee::hashNationalId('   '))->toBeNull();
    });
});

describe('national ID matching', function () {
    it('confidently matches on national ID regardless of formatting', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'national_id' => 'ETH123456']);

        $match = nidResolver()->resolve($tenant->id, new IdentitySignals(nationalId: 'eth 123 456'));

        expect($match->outcome)->toBe(IdentityMatch::MATCHED);
        expect($match->employee->id)->toBe($employee->id);
        expect($match->candidates[0]['reasons'])->toContain('national_id');
    });

    it('does not match a different national ID', function () {
        $tenant = createTenant();
        Employee::factory()->create(['tenant_id' => $tenant->id, 'national_id' => 'AAA111']);

        $match = nidResolver()->resolve($tenant->id, new IdentitySignals(nationalId: 'BBB222'));

        expect($match->outcome)->toBe(IdentityMatch::NEW);
    });

    it('keeps national ID scoped to the tenant', function () {
        $other = createTenant();
        Employee::factory()->create(['tenant_id' => $other->id, 'national_id' => 'SHARED-NID']);

        $mine = createTenant();
        $match = nidResolver()->resolve($mine->id, new IdentitySignals(nationalId: 'SHARED-NID'));

        expect($match->outcome)->toBe(IdentityMatch::NEW);
    });
});

describe('national ID via the employee API', function () {
    it('accepts and persists national_id when creating an employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        // Regression: the field was fillable and matchable but had no FormRequest
        // rule, so validated() silently dropped it end-to-end.
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees", [
            'name' => 'Hana Girma',
            'hire_date' => '2026-01-15',
            'national_id' => 'ETH-999-001',
        ])->assertCreated();

        $employee = Employee::where('tenant_id', $tenant->id)->where('name', 'Hana Girma')->first();

        expect($employee->national_id)->toBe('ETH-999-001');
        expect($employee->national_id_hash)->toBe(Employee::hashNationalId('ETH-999-001'));
    });

    it('never exposes the national ID or its hash in API responses', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $r = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees", [
            'name' => 'Private Person',
            'hire_date' => '2026-01-15',
            'national_id' => 'ETH-SECRET',
        ])->assertCreated();

        expect(json_encode($r->json()))->not->toContain('ETH-SECRET');
        $r->assertJsonMissingPath('national_id_hash');
    });
});

describe('import dedupe on national ID', function () {
    it('skips a re-import of the same person matched by national ID alone', function () {
        $tenant = createTenant();
        $importer = app(EmployeeImporter::class);

        $first = $importer->commit('batchA', [
            ['name' => 'Abebe Kebede', 'employee_code' => 'E100', 'national_id' => 'NID-777', 'hire_date' => '2026-01-01'],
        ]);
        expect($first['created'])->toBe(1);

        // Different code and name, but the same national ID → same person.
        $second = $importer->commit('batchB', [
            ['name' => 'A. Kebede', 'employee_code' => 'E999', 'national_id' => 'nid 777', 'hire_date' => '2026-01-01'],
        ]);

        expect($second['created'])->toBe(0);
        expect($second['matched'])->toBe(1);
        expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
    });
});
