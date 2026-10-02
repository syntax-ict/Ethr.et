<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Support\Csv;

/**
 * Three CSV exports built their own cells. None neutralised a spreadsheet
 * formula, so a value such as =HYPERLINK(...) typed into an employee name ran
 * when HR opened the file (OWASP "CSV injection"), and the accounting journal
 * wrote number_format()'s comma-grouped "12,345.67" into unquoted cells, so
 * every amount of 1,000 ETB or more became two columns.
 */
test('a formula-shaped value stays text, a plain number stays a number', function () {
    expect(Csv::cell('=HYPERLINK("http://x.test","Open")'))->toBe('"\'=HYPERLINK(""http://x.test"",""Open"")"')
        ->and(Csv::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(Csv::cell('+251 911 cmd'))->toBe("'+251 911 cmd")
        ->and(Csv::cell('-250.00'))->toBe('-250.00')
        ->and(Csv::cell('Kebede, Abebe'))->toBe('"Kebede, Abebe"')
        ->and(Csv::cell(null))->toBe('')
        ->and(Csv::amount(1_234_567))->toBe('12345.67');
});

test('the report export neutralises a formula in employee data', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => '=HYPERLINK("http://evil.test","Payslip")']);

    $csv = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/export", [
        'source' => 'employees',
        'columns' => ['name'],
        'format' => 'csv',
    ])->assertOk()->getContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toMatch('/(^|\n|,)"?=HYPERLINK/');
});

test('the accounting journal keeps every amount in its own column', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'gross_cents' => 2_500_000,
        'income_tax_cents' => 500_000,
        'employee_pension_cents' => 175_000,
        'employer_pension_cents' => 275_000,
        'other_deductions_cents' => 0,
        'net_cents' => 1_825_000,
    ]);

    $csv = test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/accounting/export/{$run->public_id}")
        ->assertOk()->getContent();

    $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
    $width = count($rows[0]);
    foreach ($rows as $row) {
        expect($row)->toHaveCount($width);
    }
    expect($csv)->toContain('25000.00')->and($csv)->not->toContain('25,000.00');
});
