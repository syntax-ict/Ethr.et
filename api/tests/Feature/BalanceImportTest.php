<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;

// Opening leave and loan balances from the system an organisation used before.
// Until 2026-10-20 they could only be typed in one at a time (blueprint M4, M5).

function balanceUrl(Tenant $tenant, string $kind, string $step): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/imports/balances/{$kind}/{$step}";
}

function balanceCsv(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('balances.csv', $content);
}

function balanceSetup(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E-001', 'name' => 'Abebe Kebede']);
    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);

    return [$tenant, $employee, $annual];
}

describe('leave balances', function () {
    it('previews every row and says what is wrong with each', function () {
        [$tenant] = balanceSetup();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $csv = "employee_code,leave_type_code,year,entitled_days,used_days,carried_days\n"
            ."E-001,annual,2026,18,4.5,2\n"
            ."E-404,annual,2026,18,0,0\n"
            ."E-001,sabbatical,2026,10,0,0\n"
            ."E-001,annual,2026,10,30,0\n";

        $result = test()->post(balanceUrl($tenant, 'leave', 'preview'), ['file' => balanceCsv($csv)])
            ->assertOk()->json();

        expect($result['valid'])->toBe(1)->and($result['invalid'])->toBe(3)
            ->and($result['rows'][0]['employee_name'])->toBe('Abebe Kebede')
            ->and($result['rows'][1]['errors'])->toBe(['No employee has the code E-404.'])
            ->and($result['rows'][2]['errors'])->toBe(['No leave type has the code sabbatical.'])
            ->and($result['rows'][3]['errors'])->toContain('used_days is more than entitled_days and carried_days together.');
    });

    it('sets the year\'s balance, creating or replacing it, and records the import', function () {
        [$tenant, $employee, $annual] = balanceSetup();
        LeaveBalance::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $annual->id,
            'year' => 2026, 'entitled_days' => 1.3, 'used_days' => 0, 'carried_days' => 0, 'pending_days' => 0,
        ]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $rows = [
            ['employee_code' => 'E-001', 'leave_type_code' => 'annual', 'year' => '2026', 'entitled_days' => '18', 'used_days' => '4.5', 'carried_days' => '2', 'line' => 2],
            ['employee_code' => 'E-001', 'leave_type_code' => 'ANNUAL', 'year' => '2025', 'entitled_days' => '17', 'used_days' => '17', 'carried_days' => '', 'line' => 3],
        ];

        test()->postJson(balanceUrl($tenant, 'leave', 'commit'), ['rows' => $rows])
            ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('updated', 1);

        $b2026 = LeaveBalance::where('employee_id', $employee->id)->where('year', 2026)->sole();
        expect((float) $b2026->entitled_days)->toBe(18.0)
            ->and((float) $b2026->used_days)->toBe(4.5)
            ->and((float) $b2026->carried_days)->toBe(2.0)
            ->and(LeaveBalance::where('employee_id', $employee->id)->where('year', 2025)->exists())->toBeTrue()
            ->and(AuditLog::where('action', 'leave.balances_imported')->exists())->toBeTrue();
    });

    it('changes nothing when any row is invalid, whatever the browser sends', function () {
        [$tenant, $employee] = balanceSetup();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(balanceUrl($tenant, 'leave', 'commit'), ['rows' => [
            ['employee_code' => 'E-001', 'leave_type_code' => 'annual', 'year' => '2026', 'entitled_days' => '18', 'line' => 2, 'valid' => true, 'errors' => []],
            ['employee_code' => 'E-001', 'leave_type_code' => 'annual', 'year' => '20x6', 'entitled_days' => '18', 'line' => 3, 'valid' => true, 'errors' => []],
        ]])->assertStatus(422)->assertJsonPath('committed', false);

        expect(LeaveBalance::where('employee_id', $employee->id)->exists())->toBeFalse();
    });

    it('is refused to someone who cannot adjust leave balances', function () {
        [$tenant] = balanceSetup();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->getJson(balanceUrl($tenant, 'leave', 'template'))->assertForbidden();
    });
});

describe('loan balances', function () {
    it('creates loans with their remaining balance, once', function () {
        [$tenant, $employee] = balanceSetup();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        $rows = [[
            'employee_code' => 'E-001', 'principal_etb' => '30000', 'remaining_etb' => '12500.50',
            'monthly_deduction_etb' => '2500', 'start_date' => '2025-01-01', 'reason' => 'Housing loan', 'line' => 2,
        ]];

        test()->postJson(balanceUrl($tenant, 'loans', 'commit'), ['rows' => $rows])->assertOk()->assertJsonPath('created', 1);
        test()->postJson(balanceUrl($tenant, 'loans', 'commit'), ['rows' => $rows])->assertOk()->assertJsonPath('skipped', 1);

        $loan = EmployeeLoan::where('employee_id', $employee->id)->sole();
        expect($loan->amount_cents)->toBe(3_000_000)
            ->and($loan->remaining_cents)->toBe(1_250_050)
            ->and($loan->monthly_deduction_cents)->toBe(250_000)
            ->and($loan->status)->toBe('active')
            ->and($loan->reason)->toBe('Housing loan');
    });

    it('refuses a remaining balance above the principal', function () {
        [$tenant] = balanceSetup();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        $csv = "employee_code,principal_etb,remaining_etb,monthly_deduction_etb,start_date,reason\n"
            ."E-001,1000,2000,100,2025-01-01,\n";

        test()->post(balanceUrl($tenant, 'loans', 'preview'), ['file' => balanceCsv($csv)])
            ->assertOk()
            ->assertJsonPath('rows.0.errors', ['remaining_etb is more than principal_etb.']);
    });

    it('is refused to someone who cannot manage loans', function () {
        [$tenant] = balanceSetup();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(balanceUrl($tenant, 'loans', 'template'))->assertForbidden();
    });
});

it('never matches an employee code from another organisation', function () {
    [$tenant] = balanceSetup();
    $other = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $other);
    LeaveType::factory()->create(['tenant_id' => $other->id, 'code' => 'annual']);

    $csv = "employee_code,leave_type_code,year,entitled_days\nE-001,annual,2026,16\n";

    test()->post(balanceUrl($other, 'leave', 'preview'), ['file' => balanceCsv($csv)])
        ->assertOk()
        ->assertJsonPath('rows.0.errors', ['No employee has the code E-001.']);
});
