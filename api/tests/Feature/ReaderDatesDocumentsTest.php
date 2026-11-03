<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Letters\EmploymentLetters;
use App\Services\Payroll\PayslipPdfService;
use App\Services\Report\ReportEngine;
use App\Support\ReaderDates;

/*
 * Documents and exports in the reader's calendar, clock and language. The
 * payslip printed English and the stored Gregorian `period_label` for
 * everyone, the employment letter Gregorian dates in either language, and a
 * report's check-in and check-out the raw UTC instant.
 */

function documentsUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function documentsPayslip(Tenant $tenant): PayrollEntry
{
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id, 'period_label' => 'October 2026',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved',
    ]);

    return PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'deductions' => [['type' => 'loan', 'amount_cents' => 50_000]],
    ]);
}

// ── Payslip ──

test('a payslip names its period in the reader\'s calendar and language', function () {
    $tenant = createTenant();
    $entry = documentsPayslip($tenant);
    $payslips = app(PayslipPdfService::class);

    $english = $payslips->data($entry, new ReaderDates('ethiopian', '24h', 'en', 'Africa/Addis_Ababa'));
    $amharic = $payslips->data($entry, new ReaderDates('ethiopian', 'ethiopian', 'am', 'Africa/Addis_Ababa'));
    $gregorian = $payslips->data($entry, new ReaderDates('gregorian', '24h', 'en', 'Africa/Addis_Ababa'));

    expect($english['period'])->toBe('Tikimt 2019')
        ->and($english['deductions'][0]['label'])->toBe('Loan repayment')
        ->and($amharic['period'])->toBe('ጥቅምት 2019')
        ->and($amharic['deductions'][0]['label'])->toBe('የብድር ክፍያ')
        ->and($gregorian['period'])->toBe('October 2026');

    $html = view('payslip', $amharic)->render();
    expect($html)->toContain('የጥቅምት 2019 የደመወዝ ወረቀት')
        ->toContain('የተጣራ ክፍያ')
        ->not->toContain('Payslip for')
        ->not->toContain('Net Pay');
});

test('a payslip downloads for its reader', function () {
    $tenant = createTenant();
    $entry = documentsPayslip($tenant);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN, 'locale' => 'am', 'preferences' => ['locale_chosen' => true]], $tenant);

    $response = test()->get(documentsUrl($tenant, "payroll/payslips/{$entry->public_id}/pdf"))->assertOk();

    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

// ── Employment letter ──

test('a letter is dated in the organisation\'s calendar, with the Gregorian date beside it', function () {
    $tenant = createTenant(['name' => 'Syntax Technology']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2022-03-01']);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $letters = app(EmploymentLetters::class);

    $english = $letters->data($letters->issue($letters->request($employee, ['purpose' => 'Bank']), $hr));
    $amharic = $letters->data($letters->issue($letters->request($employee, ['purpose' => 'Visa', 'language' => 'am']), $hr));

    expect($english['hired_on'])->toBe('Yekatit 22, 2014 (01 Mar 2022)')
        ->and($amharic['hired_on'])->toStartWith('የካቲት 22, 2014 (');

    $tenant->update(['settings' => ['calendar' => 'gregorian']]);
    $gregorian = $letters->data($letters->issue($letters->request($employee, ['purpose' => 'Embassy']), $hr));

    expect($gregorian['hired_on'])->toBe('01 Mar 2022');
});

// ── Report files ──

/** @return list<array<string, mixed>> */
function documentsAttendanceRows(): array
{
    // 05:30 and 21:15 UTC are 08:30 and, the next day, 00:15 in Addis Ababa.
    return [[
        'employee_name' => 'Abebe',
        'date' => '2026-11-01',
        'check_in' => '2026-11-01T05:30:00+00:00',
        'check_out' => '2026-11-01T21:15:00+00:00',
    ]];
}

test('a report PDF writes dates and times for its reader', function () {
    $reader = new ReaderDates('ethiopian', 'ethiopian', 'am', 'Africa/Addis_Ababa');

    expect(app(ReportEngine::class)->presentable(documentsAttendanceRows(), $reader))->toBe([[
        'ሠራተኛ' => 'Abebe',
        'ቀን' => 'ጥቅምት 22, 2019',
        'መግቢያ' => '2:30 ጠዋት',
        // After midnight, so it says which day.
        'መውጫ' => 'ጥቅምት 23, 2019, 6:15 ሌሊት',
    ]]);
});

test('a report spreadsheet keeps sortable values, with the Ethiopian date beside the ISO one', function () {
    $engine = app(ReportEngine::class);

    expect($engine->toCsv(documentsAttendanceRows(), new ReaderDates('ethiopian', '24h', 'en', 'Africa/Addis_Ababa')))
        ->toBe("Employee,Date,Date (Ethiopian calendar),Check-in,Check-out\r\nAbebe,2026-11-01,\"Tikimt 22, 2019\",2026-11-01 08:30,2026-11-02 00:15")
        ->and($engine->toCsv(documentsAttendanceRows(), new ReaderDates('gregorian', '24h', 'en', 'Africa/Addis_Ababa')))
        ->toBe("Employee,Date,Check-in,Check-out\r\nAbebe,2026-11-01,2026-11-01 08:30,2026-11-02 00:15");
});

test('the attendance report sends check-in and check-out as instants', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'date' => '2026-10-30',
        'check_in' => '2026-10-30 05:30:00',
        'check_out' => '2026-10-30 14:00:00',
    ]);

    $response = test()->postJson(documentsUrl($tenant, 'reports/generate'), ['source' => 'attendance'])->assertOk();

    expect($response->json('data.0.check_in'))->toBe('2026-10-30T05:30:00+00:00');
});
