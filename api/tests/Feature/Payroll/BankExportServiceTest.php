<?php

declare(strict_types=1);

use App\Enums\UserRole;
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

describe('the bank export the run page downloads', function () {
    // GET /payroll/runs/{run}/export/bank built its rows with
    // `bankDetails->first()`: no `is_primary`, no ordering. An employee with
    // two accounts could be paid into the old one, in the file finance uploads.
    it('pays into the account marked primary, not the first on record', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        EmployeeBankDetail::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'bank_name' => 'Old Bank', 'account_number' => '000', 'is_primary' => false,
        ]);
        EmployeeBankDetail::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'bank_name' => 'Awash Bank', 'account_number' => '2000999', 'is_primary' => true,
        ]);
        PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id,
            'employee_id' => $employee->id, 'net_cents' => 1_500_000,
        ]);

        test()->getJson("/api/v1/payroll/runs/{$run->public_id}/export/bank")
            ->assertOk()
            ->assertJsonPath('rows.0.bank_name', 'Awash Bank')
            ->assertJsonPath('rows.0.account_number', '2000999');
    });
});
