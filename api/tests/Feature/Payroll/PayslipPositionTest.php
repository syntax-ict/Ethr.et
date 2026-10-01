<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Services\Payroll\PayslipPdfService;
use Barryvdh\DomPDF\Facade\Pdf;

/*
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
