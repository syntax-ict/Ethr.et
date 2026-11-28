<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Support\ReaderDates;
use Carbon\Carbon;
use Tests\Accuracy\Restated\RestatedEntries;
use Tests\Accuracy\Restated\TimesheetCompare;
use Tests\Accuracy\Restated\TimesheetFigures;
use Tests\Accuracy\Restated\TimesheetFixture;
use Tests\Accuracy\Restated\TimesheetOutputs;

/*
 * The monthly timesheet's outputs against the ledger they restate. One small
 * organisation and a fortnight of attendance (TimesheetFixture); the ledger is
 * built by TimesheetLedger; then the grid (TimesheetReport::grid), the Excel
 * workbook (read cell by cell) and the PDF (read as text with positions) are
 * each read and held against it, per person and in total: days of each kind,
 * worked, scheduled and short hours, late and early minutes, overtime under each
 * rate, and the code of every day.
 *
 * Hours are compared after the outputs' own format: minutes / 60, one decimal,
 * a trailing ".0" dropped. A footer is compared as the ledger's minutes of
 * everyone added, then the same format.
 *
 * Not compared: payable days and the two overtime-limit columns, which the
 * ledger does not hold (they come from payroll's limit functions).
 *
 * A difference is a finding (findings.php), never a reason to adjust an output.
 */

it('shows on the grid, the workbook and the PDF what the ledger holds', function (string $output): void {
    $id = 'GR-TIMESHEET-001';

    $f = TimesheetFixture::build();
    try {
        $employees = Employee::query()->where('tenant_id', $f['tenant']->id)->orderBy('name')->get()->all();
        $filters = ['from' => TimesheetFixture::FROM, 'to' => TimesheetFixture::TO];
        $reader = ReaderDates::forTenant($f['tenant'], 'en')->withCalendar('gregorian');
        $rateColumns = TimesheetFigures::rateColumns($f['tenant']->id, Carbon::parse(TimesheetFixture::FROM));

        $ledger = TimesheetOutputs::ledger($f['tenant'], $employees, TimesheetFixture::FROM, TimesheetFixture::TO);
        $read = match ($output) {
            'grid' => TimesheetOutputs::grid($f['tenant'], $f['viewer'], $filters, $rateColumns),
            'xlsx' => TimesheetOutputs::xlsx($f['tenant'], $f['viewer'], $filters, $reader, $rateColumns),
            'pdf' => TimesheetOutputs::pdf($f['tenant'], $f['viewer'], $filters, $reader, $rateColumns, count($ledger['days'])),
        };
        // The days sheet of the workbook and the PDF carry codes; their summary carries the figures.
        $result = TimesheetCompare::against($output, $ledger, $read, $rateColumns);
    } finally {
        Carbon::setTestNow();
    }

    fwrite(STDERR, "RESTATED {$id} {$output}: ".count($ledger['employees']).' people, '.count($ledger['days']).' days, '.$result['compared'].' figures compared
');

    RestatedEntries::settle($id.' '.$output, $result['misses'], $result['compared']);
})->with(['grid', 'xlsx', 'pdf']);
