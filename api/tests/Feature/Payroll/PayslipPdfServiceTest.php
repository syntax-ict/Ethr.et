<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayslipPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

/*
 * PayslipPdfService, directly (was PayslipPositionTest, which held the first
 * test below only; audit B11).
 *
 * PayslipPdfService read `$position->name`. Positions have a `title` column and
 * no `name`, so every payslip printed a blank position. Found when the API
 * resources were given @mixin types and PHPStan could finally see the property.
 */
it('prints the employee name and position title on the payslip', function () {
    $tenant = createTenant();
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Senior Accountant']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position->id, 'name' => 'Abebe Kebede']);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $captured = null;
    $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')->andReturnSelf();
    $pdf->shouldReceive('setOption')->andReturnSelf();
    $pdf->shouldReceive('output')->andReturn('%PDF');
    Pdf::shouldReceive('loadView')->once()->andReturnUsing(function (string $view, array $data) use (&$captured, $pdf) {
        $captured = $data;

        return $pdf;
    });

    app(PayslipPdfService::class)->generate($entry);

    // Both fields read properties that do not exist (`position->name`,
    // `employee->full_name`), so both were always blank.
    expect($captured['position'])->toBe('Senior Accountant')
        ->and($captured['employee_name'])->toBe('Abebe Kebede');
});

/**
 * Generates with DomPDF mocked and returns the data handed to the view.
 *
 * @return array<string, mixed>
 */
function payslipPdfCapture(PayrollEntry $entry): array
{
    $captured = [];
    $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')->with('a5', 'portrait')->andReturnSelf();
    $pdf->shouldReceive('setOption')->andReturnSelf();
    $pdf->shouldReceive('output')->andReturn('%PDF');
    Pdf::shouldReceive('loadView')->once()->andReturnUsing(function (string $view, array $data) use (&$captured, $pdf) {
        expect($view)->toBe('payslip');
        $captured = $data;

        return $pdf;
    });

    app(PayslipPdfService::class)->generate($entry);

    return $captured;
}

it('renders a real PDF from the payslip view', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'Meskerem 2019']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $bytes = app(PayslipPdfService::class)->generate($entry);

    expect(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

// DejaVu Sans has no Ethiopic glyphs, so every Amharic name, department and
// period printed as empty boxes (audit N79). The bundled Noto Sans Ethiopic
// carries them; subsetting keeps the file small with both faces embedded.
it('embeds an Ethiopic face for Amharic text, subset to a small file', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'አበበ ከበደ']);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'መስከረም 2019']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $bytes = app(PayslipPdfService::class)->generate($entry);

    expect($bytes)->toContain('NotoSansEthiopic')
        ->and(strlen($bytes))->toBeLessThan(200_000);
});

it('hands the view the run, the amounts in cents and the tenant name', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-0042']);
    // The period is named from the run's dates, not its stored label: Tikimt
    // 2019 runs from 11 October to 9 November 2026.
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'Tikimt 2019', 'period_start' => '2026-10-11', 'period_end' => '2026-11-09', 'status' => 'approved']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'gross_cents' => 1_000_000,
        'income_tax_cents' => 150_000,
        'net_cents' => 780_000,
    ]);

    $data = payslipPdfCapture($entry);

    expect($data['tenant_name'])->toBe($tenant->name)
        ->and($data['period'])->toBe('Tikimt 2019')
        ->and($data['employee_code'])->toBe('EMP-0042')
        ->and($data['gross_cents'])->toBe(1_000_000)
        ->and($data['income_tax_cents'])->toBe(150_000)
        ->and($data['net_cents'])->toBe(780_000)
        ->and($data['is_voided'])->toBeFalse();
});

it('marks a payslip from a voided run as void', function () {
    $tenant = createTenant();
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'voided']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);

    expect(payslipPdfCapture($entry)['is_voided'])->toBeTrue();
});

it('prints blanks rather than failing for an employee with no department or position', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null, 'position_id' => null]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $data = payslipPdfCapture($entry);

    expect($data['department'])->toBe('')
        ->and($data['position'])->toBe('');
});

// Known issue K-48: the payslip printed basic, the allowance rules and gross,
// and never the overtime the engine paid, so with overtime the printed lines
// did not add up to gross. The figures are golden case G8's (overtime 150.00)
// with a transport allowance beside it, worked out by hand.
it('prints the overtime line, so the earnings add up to gross', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 880000, 'hire_date' => '2025-01-01', 'status' => 'confirmed',
    ]);
    PayrollRule::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'Transport allowance', 'type' => 'fixed', 'category' => 'allowance',
        'formula' => ['amount_cents' => 60000], 'is_taxable' => false, 'is_active' => true, 'sort_order' => 10,
    ]);
    // Two hours past an 08:30-17:30 shift.
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => Shift::factory()->create(['tenant_id' => $tenant->id])->id,
        'date' => '2026-10-15',
        'check_in' => Carbon::parse('2026-10-15 08:30', 'Africa/Addis_Ababa')->utc(),
        'check_out' => Carbon::parse('2026-10-15 19:30', 'Africa/Addis_Ababa')->utc(),
    ]);
    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $user->id)->run;
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

    $data = payslipPdfCapture($entry);
    $html = view('payslip', $data)->render();

    // Hourly rate 8,800 / 22 / 8 = 50; two normal hours at 1.5 = 150.
    // Gross 8,800 + 150 + 600 = 9,550.
    $printed = $data['basic_salary_cents'] + ($data['overtime_cents'] ?? 0) + (int) collect($data['allowances'])->sum('amount_cents');
    expect($data['gross_cents'])->toBe(955000)
        ->and($printed)->toBe($data['gross_cents'])
        ->and($html)->toContain('Overtime')
        ->and($html)->toContain('150.00');
});

it('prints no overtime line when the entry paid none', function () {
    $tenant = createTenant();
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);

    $data = payslipPdfCapture($entry);

    expect($data['overtime_cents'] ?? 0)->toBe(0)
        ->and(view('payslip', $data)->render())->not->toContain('Overtime');
});
