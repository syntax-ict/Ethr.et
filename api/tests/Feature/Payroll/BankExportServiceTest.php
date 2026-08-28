<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\BankExportService;

function makeEntryWithBank(
    array $employeeOverrides = [],
    ?array $bankDetail = ['bank_name' => 'Commercial Bank of Ethiopia', 'account_number' => '1000123456789', 'is_primary' => true],
    int $netCents = 1_500_000,
): PayrollEntry {
    $tenant = createTenant();
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(array_merge(['tenant_id' => $tenant->id], $employeeOverrides));

    if ($bankDetail !== null) {
        EmployeeBankDetail::create(array_merge([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
        ], $bankDetail));
    }

    return PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'net_cents' => $netCents,
    ]);
}

describe('generateCsv', function () {
    it('populates the employee name, bank name and account number', function () {
        $entry = makeEntryWithBank(['name' => 'Abebe Kebede', 'employee_code' => 'EMP-001']);
        $run = $entry->payrollRun;

        $csv = app(BankExportService::class)->generateCsv($run);

        expect($csv)->toContain('EMP-001,Abebe Kebede,Commercial Bank of Ethiopia,1000123456789,15000.00,ETB');
    });

    it('prefers the bank detail marked primary over others', function () {
        $tenant = createTenant();
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sara Tesfaye']);

        EmployeeBankDetail::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'bank_name' => 'Old Bank',
            'account_number' => '000',
            'is_primary' => false,
        ]);
        EmployeeBankDetail::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'bank_name' => 'Awash Bank',
            'account_number' => '999888777',
            'is_primary' => true,
        ]);

        $entry = PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'net_cents' => 100_000,
        ]);

        $csv = app(BankExportService::class)->generateCsv($entry->payrollRun);

        expect($csv)->toContain('Awash Bank,999888777')
            ->and($csv)->not->toContain('Old Bank');
    });

    it('leaves the bank columns blank rather than erroring when no bank detail exists', function () {
        $entry = makeEntryWithBank(['name' => 'No Bank Employee'], bankDetail: null);

        $csv = app(BankExportService::class)->generateCsv($entry->payrollRun);

        expect($csv)->toContain('No Bank Employee,,,');
    });

    it('quotes a name containing a comma', function () {
        $entry = makeEntryWithBank(['name' => 'Kebede, Abebe']);

        $csv = app(BankExportService::class)->generateCsv($entry->payrollRun);

        expect($csv)->toContain('"Kebede, Abebe"');
    });
});

describe('generateCbeFormat', function () {
    it('pads the account number to 13 digits and includes the employee name', function () {
        $entry = makeEntryWithBank(
            ['name' => 'Tigist Alemu'],
            ['bank_name' => 'CBE', 'account_number' => '12345', 'is_primary' => true],
        );

        $cbe = app(BankExportService::class)->generateCbeFormat($entry->payrollRun);

        expect($cbe)->toContain('0000000012345|15000.00|Tigist Alemu');
    });

    it('skips an employee with no bank account rather than emitting a malformed row', function () {
        $entry = makeEntryWithBank(['name' => 'Unbanked Employee'], bankDetail: null);

        $cbe = app(BankExportService::class)->generateCbeFormat($entry->payrollRun);

        expect($cbe)->toBe('');
    });
});
