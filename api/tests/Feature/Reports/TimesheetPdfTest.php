<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Report\TimesheetPdf;
use App\Support\ReaderDates;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The monthly timesheet on A4 landscape. "Now" is Friday 2026-10-23 in Addis
 * Ababa; the sheet covers 2026-10-12 to 2026-10-25, which on the Ethiopian
 * calendar starts on the second day of Tikimt.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-23 10:00', 'Africa/Addis_Ababa'));
});

afterEach(fn () => Carbon::setTestNow());

function tpUrl(Tenant $tenant, string $query = 'from=2026-10-12&to=2026-10-25'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/timesheet/pdf?{$query}";
}

/** @return array{tenant: Tenant, employee: Employee} */
function tpTenant(): array
{
    $tenant = createTenant();
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Abebe Kebede', 'name_am' => 'አበበ ከበደ', 'badge_number' => '4412']);

    foreach (['2026-10-12', '2026-10-13'] as $day) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id, 'date' => $day,
            'check_in' => Carbon::parse("$day 08:30", 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse("$day 17:30", 'Africa/Addis_Ababa')->utc(),
            'status' => AttendanceStatus::PRESENT,
        ]);
    }

    return compact('tenant', 'employee');
}

function tpData(Tenant $tenant, string $locale = 'en', string $calendar = 'gregorian', bool $withPay = false): array
{
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN, 'display_name' => 'Hana Tadesse']);
    $reader = ReaderDates::forTenant($tenant, $locale)->withCalendar($calendar);

    return app(TimesheetPdf::class)->data($tenant, $user, ['from' => '2026-10-12', 'to' => '2026-10-25'], $reader, $withPay);
}

test('the timesheet downloads as an A4 landscape PDF', function () {
    ['tenant' => $tenant] = tpTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->get(tpUrl($tenant))->assertOk();
    $bytes = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('timesheet-')
        ->and(substr($bytes, 0, 5))->toBe('%PDF-');

    // Landscape: the page is wider than it is tall.
    preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $bytes, $box);
    expect($box)->toHaveCount(3)
        ->and((float) $box[1])->toBeGreaterThan((float) $box[2])
        ->and((float) $box[1])->toEqualWithDelta(841.89, 1.0);
});

test('it prints the title in English and in Amharic, whatever the reader\'s language', function () {
    ['tenant' => $tenant] = tpTenant();

    foreach (['en', 'am', 'om'] as $locale) {
        $titles = tpData($tenant, $locale)['titles'];
        expect($titles['en'])->toBe('Monthly timesheet')
            ->and($titles['am'])->toBe('ወርሃዊ የሰዓት መዝገብ');
    }
});

test('it prints a column per day, numbered in the reader\'s calendar', function () {
    ['tenant' => $tenant] = tpTenant();

    $gregorian = tpData($tenant, 'en', 'gregorian');
    $ethiopian = tpData($tenant, 'am', 'ethiopian');

    expect($gregorian['days'])->toHaveCount(14)
        ->and($gregorian['days'][0]['number'])->toBe(12)
        // 11 October 2026 is Tikimt 1, 2019.
        ->and($ethiopian['days'][0]['number'])->toBe(2)
        ->and($ethiopian['days'][13]['number'])->toBe(15)
        ->and($gregorian['generated'])->toContain('Hana Tadesse')
        // Saturday and Sunday are marked for shading.
        ->and(array_column($gregorian['days'], 'weekend'))->toBe([false, false, false, false, false, true, true, false, false, false, false, false, true, true]);
});

test('each person has their name in both languages, their position, shift and device id', function () {
    ['tenant' => $tenant] = tpTenant();

    $row = tpData($tenant)['rows'][0];

    expect($row['name'])->toBe('Abebe Kebede')
        ->and($row['name_am'])->toBe('አበበ ከበደ')
        ->and($row['code'])->not->toBeNull()
        ->and($row['shift'])->toBe('Morning Shift')
        ->and($row['device'])->toBe('4412')
        ->and($row['cells'])->toHaveCount(14)
        ->and($row['cells'][0]['short'])->toBe('P');
});

test('it carries the legend of every code, the signature lines and a stamp box', function () {
    ['tenant' => $tenant] = tpTenant();

    $data = tpData($tenant, 'en');

    expect(array_column($data['legend'], 'short'))->toBe(['P', 'L', 'E', 'M', 'A', 'AL', 'SL', 'ML', 'OL', 'FD', '½', 'H', 'R'])
        ->and($data['signatures'])->toBe(['Prepared by', 'Checked by', 'Approved by'])
        ->and($data['stamp'])->toBe('Stamp');

    $am = tpData($tenant, 'am');
    expect($am['signatures'])->toBe(['ያዘጋጀው', 'ያረጋገጠው', 'ያጸደቀው']);
});

test('it states the rules it applied, from the shifts and the statutory rows', function () {
    ['tenant' => $tenant] = tpTenant();

    $rules = implode(' ', tpData($tenant, 'en')['rules']);

    expect($rules)->toContain('Morning Shift')
        ->and($rules)->toContain('late after 15 minutes')
        ->and($rules)->toContain('4 hours a day and 12 hours a week')
        ->and($rules)->toContain('not deducted from pay');
});

test('payable days are printed only for someone who may see payroll', function () {
    ['tenant' => $tenant] = tpTenant();

    expect(array_column(tpData($tenant, 'en', 'gregorian', false)['columns'], 'key'))->not->toContain('payable_days')
        ->and(array_column(tpData($tenant, 'en', 'gregorian', true)['columns'], 'key'))->toContain('payable_days');
});

test('it prints one overtime column for each rate the organisation pays', function () {
    ['tenant' => $tenant] = tpTenant();

    $labels = array_column(tpData($tenant, 'en')['columns'], 'label');

    expect($labels)->toContain('Overtime ×1.5')->toContain('Overtime ×1.75')->toContain('Overtime ×2')->toContain('Overtime ×2.5');
});

test('it renders in Amharic and in Afaan Oromoo without failing on the script', function () {
    ['tenant' => $tenant] = tpTenant();
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);

    foreach (['am', 'om'] as $locale) {
        $bytes = app(TimesheetPdf::class)->render(
            $tenant, $user, ['from' => '2026-10-12', 'to' => '2026-10-25'], ReaderDates::forTenant($tenant, $locale)->withCalendar('ethiopian'), false,
        );
        expect(substr($bytes, 0, 5))->toBe('%PDF-');
    }
});

test('a list longer than a printed sheet holds is refused with the way to narrow it, not cut', function () {
    ['tenant' => $tenant] = tpTenant();
    Employee::factory()->count(TimesheetPdf::MAX_PEOPLE)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(tpUrl($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['department']);
});

test('only someone who may run reports can print it', function () {
    ['tenant' => $tenant] = tpTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->get(tpUrl($tenant))->assertForbidden();
});

test('a bad filter is refused as the grid refuses it', function () {
    ['tenant' => $tenant] = tpTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(tpUrl($tenant, 'to=2026-10-25'))->assertStatus(422);
});

test('the signatures partial prints its lines and the stamp box, and the payroll schedule still says what it did', function () {
    $html = view('pdf.partials.signatures', ['labels' => ['Prepared by', 'Checked by', 'Authorised signature and stamp']])->render();

    expect($html)->toContain('Prepared by')->toContain('Checked by')->toContain('Authorised signature and stamp')
        ->and($html)->not->toContain('class="stamp"');

    expect(view('pdf.partials.signatures', ['labels' => ['A'], 'stamp' => 'Stamp'])->render())->toContain('class="stamp"');
});

test('a list too long for a sheet is refused before the grid is built, not after', function () {
    ['tenant' => $tenant] = tpTenant();
    Employee::factory()->count(TimesheetPdf::MAX_PEOPLE)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    DB::enableQueryLog();
    test()->getJson(tpUrl($tenant))->assertStatus(422);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Building the grid for 51 people is hundreds of queries; counting them is a handful.
    expect($queries)->toBeLessThan(60);
});

test('payable days sit after the early-leave days, where the screen has them', function () {
    ['tenant' => $tenant] = tpTenant();

    $keys = array_column(tpData($tenant, 'en', 'gregorian', true)['columns'], 'key');

    expect($keys[array_search('early_days', $keys, true) + 1])->toBe('payable_days');
});
