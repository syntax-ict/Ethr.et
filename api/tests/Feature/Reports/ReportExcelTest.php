<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Support\Xlsx;
use Carbon\Carbon;

/*
 * Reports as an Excel workbook, the ad hoc export and the monthly timesheet.
 * A workbook is a zip of XML, so the tests open it as one: the header row,
 * a number typed as a number, the frozen header, the sheets.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-23 10:00', 'Africa/Addis_Ababa'));
});

afterEach(fn () => Carbon::setTestNow());

function xlUrl(Tenant $tenant, string $path, string $query = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/{$path}".($query === '' ? '' : "?{$query}");
}

/**
 * A workbook as `[sheet name => [row number => [column letter => [type, value]]]]`,
 * where type is `n` for a number and `s` for text.
 *
 * @return array{sheets: array<string, array<int, array<string, array{0: string, 1: string}>>>, xml: array<string, string>}
 */
function xlRead(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'xl-test-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        foreach ((new SimpleXMLElement($xml))->si as $si) {
            $shared[] = (string) $si->t.implode('', array_map('strval', iterator_to_array($si->r ?? [], false)));
        }
    }

    $book = new SimpleXMLElement((string) $zip->getFromName('xl/workbook.xml'));
    $names = [];
    foreach ($book->sheets->sheet as $sheet) {
        $names[] = (string) $sheet['name'];
    }

    $sheets = [];
    $raw = [];
    foreach ($names as $i => $name) {
        $xml = (string) $zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml');
        $raw[$name] = $xml;
        foreach ((new SimpleXMLElement($xml))->sheetData->row ?? [] as $row) {
            foreach ($row->c as $cell) {
                preg_match('/^([A-Z]+)(\d+)$/', (string) $cell['r'], $m);
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    default => (string) $cell->v,
                };
                $sheets[$name][(int) $m[2]][$m[1]] = [in_array($type, ['s', 'inlineStr', 'str'], true) ? 's' : 'n', $value];
            }
        }
    }

    $zip->close();
    unlink($path);

    return ['sheets' => $sheets, 'xml' => $raw];
}

/** @return list<string> the text of one row, in column order */
function xlRowText(array $sheet, int $row): array
{
    return array_values(array_map(fn (array $cell): string => $cell[1], $sheet[$row] ?? []));
}

function xlExport(Tenant $tenant, array $body)
{
    return test()->postJson(xlUrl($tenant, 'export'), ['format' => 'xlsx', ...$body]);
}

test('an ad hoc report is an Excel file with a bold frozen header and the rows of the CSV', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'email' => 'abebe@example.test']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => '=HYPERLINK("http://x.test","Pay")', 'email' => 'x@example.test']);

    $response = xlExport($tenant, ['source' => 'employees', 'columns' => ['name', 'email'], 'sort_by' => 'name']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe(Xlsx::MIME)
        ->and($response->headers->get('Content-Disposition'))->toMatch('/filename="report-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx"/');

    $book = xlRead($response->getContent());
    $sheet = array_values($book['sheets'])[0];

    expect(xlRowText($sheet, 1))->toBe(['Name', 'Email'])
        ->and(collect($sheet)->flatten(1)->pluck(1)->all())->toContain('Abebe Kebede', 'abebe@example.test')
        // A text cell is text: a name that looks like a formula is a name, not a formula.
        ->and($sheet[2]['A'][0])->toBe('s')
        ->and(array_values($book['xml'])[0])->toContain('state="frozen"')
        ->and(array_values($book['xml'])[0])->not->toContain('<f>');

    $csv = test()->postJson(xlUrl($tenant, 'export'), ['source' => 'employees', 'columns' => ['name', 'email'], 'sort_by' => 'name', 'format' => 'csv'])->getContent();
    expect(substr_count($csv, "\n") + 1)->toBe(count($sheet));
});

test('birr in a workbook is a number a column can add up', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'net_cents' => 1_234_550]);

    $sheet = array_values(xlRead(xlExport($tenant, ['source' => 'payroll'])->assertOk()->getContent())['sheets'])[0];

    $column = array_search('Net pay (ETB)', xlRowText($sheet, 1), true);
    expect($column)->not->toBeFalse();
    $letter = array_keys($sheet[1])[$column];

    expect($sheet[2][$letter])->toBe(['n', '12345.5']);
});

test('a note about a cut report is the last line of the workbook, as in the CSV', function () {
    $sheets = xlRead(Xlsx::render([Xlsx::table('Rows', [['A' => 1, 'B' => 'x']], 'Showing 1 of 5')]));

    $rows = array_values($sheets['sheets'])[0];
    expect(xlRowText($rows, 1))->toBe(['A', 'B'])
        ->and($rows[2]['A'])->toBe(['n', '1'])
        ->and(xlRowText($rows, 4))->toBe(['Showing 1 of 5']);
});

test('sheet names are made safe and unique, and more than one sheet is written', function () {
    $book = xlRead(Xlsx::render([
        ['name' => 'Days: a/b?', 'header' => ['A'], 'rows' => [[1]]],
        ['name' => 'Days  a b ', 'header' => ['A'], 'rows' => [[2]], 'bold_last' => true],
        ['name' => str_repeat('x', 40), 'header' => ['A'], 'rows' => []],
    ]));

    $names = array_keys($book['sheets']);
    expect($names[0])->toBe('Days  a b')
        ->and($names[1])->not->toBe($names[0])
        ->and(mb_strlen($names[2]))->toBe(31)
        ->and(count($names))->toBe(3);
});

test('the payroll source needs payroll.viewAll in a workbook as in a CSV', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    xlExport($tenant, ['source' => 'payroll'])->assertForbidden();
});

test('a workbook holds only this organisations people, and another organisation cannot reach them', function () {
    $a = createTenant();
    Employee::factory()->create(['tenant_id' => $a->id, 'name' => 'Zebiba Tilahun']);
    $b = createTenant();
    Employee::factory()->create(['tenant_id' => $b->id, 'name' => 'Bekele Girma']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $b);

    $mine = xlExport($b, ['source' => 'employees', 'columns' => ['name']])->assertOk()->getContent();
    $text = collect(array_values(xlRead($mine)['sheets'])[0])->flatten(1)->pluck(1)->all();
    expect($text)->toContain('Bekele Girma')->not->toContain('Zebiba Tilahun');

    $response = xlExport($a, ['source' => 'employees', 'columns' => ['name']]);
    expect($response->status())->toBeIn([401, 403, 404])
        ->and($response->getContent())->not->toContain('Zebiba');
});

test('a viewer who reaches only their department gets only their department in the workbook', function () {
    $tenant = createTenant();
    $x = Department::factory()->create(['tenant_id' => $tenant->id]);
    $y = Department::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lead Person', 'department_id' => $x->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Same Department', 'department_id' => $x->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Other Department', 'department_id' => $y->id]);
    test()->actingAs(createScopedUser($tenant, $lead->id, 'department', ['report.generate']));

    $response = xlExport($tenant, ['source' => 'employees', 'columns' => ['name']])->assertOk();
    $text = collect(array_values(xlRead($response->getContent())['sheets'])[0])->flatten(1)->pluck(1)->all();

    expect($text)->toContain('Same Department')->not->toContain('Other Department');
});

test('a viewer who reaches only their team gets only their team in the workbook', function () {
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lead Person']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $lead->id, 'name' => 'Team Member']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Outside Person']);
    test()->actingAs(createScopedUser($tenant, $lead->id, 'direct_reports', ['report.generate']));

    $response = xlExport($tenant, ['source' => 'employees', 'columns' => ['name']])->assertOk();
    $text = collect(array_values(xlRead($response->getContent())['sheets'])[0])->flatten(1)->pluck(1)->all();

    expect($text)->toContain('Team Member')->not->toContain('Outside Person');
});

test('the export validation refuses a format that is not offered', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    xlExport($tenant, ['source' => 'employees', 'format' => 'ods'])->assertUnprocessable()->assertJsonMissingPath('id');
});

/** @return array{tenant: Tenant} two people in two departments, each present one day */
function xlTimesheetFixture(): array
{
    $tenant = createTenant();
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $ops = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    $fin = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

    foreach ([['Abebe Kebede', 'አበበ ከበደ', $ops], ['Hana Tadesse', 'ሀና ታደሰ', $fin]] as [$name, $amharic, $department]) {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => $name, 'name_am' => $amharic, 'department_id' => $department->id]);
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id, 'date' => '2026-10-12',
            'check_in' => Carbon::parse('2026-10-12 08:30', 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse('2026-10-12 17:30', 'Africa/Addis_Ababa')->utc(),
            'status' => AttendanceStatus::PRESENT,
        ]);
    }

    return compact('tenant');
}

test('the timesheet workbook has the days and the summary, for the same people as the timesheet', function () {
    ['tenant' => $tenant] = xlTimesheetFixture();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $query = 'from=2026-10-12&to=2026-10-25';

    $grid = test()->getJson(xlUrl($tenant, 'timesheet', $query))->assertOk();
    $response = test()->get(xlUrl($tenant, 'timesheet/xlsx', $query))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(Xlsx::MIME)
        ->and($response->headers->get('Content-Disposition'))->toMatch('/filename="timesheet-.*\.xlsx"/');

    $book = xlRead($response->getContent());
    expect(array_keys($book['sheets']))->toBe(['Days', 'Summary']);

    $days = $book['sheets']['Days'];
    $header = collect($days)->search(fn (array $row): bool => ($row['A'][1] ?? null) === 'No.');
    $people = [];
    for ($i = $header + 1; isset($days[$i]); $i++) {
        $people[] = $days[$i]['C'][1];
    }

    expect($people)->toBe(array_column($grid->json('data'), 'name'))
        ->and($days[1]['A'][1])->toBe($tenant->name)
        ->and($days[3]['A'][1])->toContain(' | ')
        ->and(ctype_digit($days[$header]['H'][1]))->toBeTrue()
        ->and($book['xml']['Days'])->toContain('state="frozen"');

    $summary = $book['sheets']['Summary'];
    $last = max(array_keys($summary));
    expect($summary[$last]['B'][1])->toContain('(2)')
        ->and($summary[$last]['C'][0])->toBe('n');
});

test('the timesheet workbook follows the same gates and the same organisation as the PDF', function () {
    ['tenant' => $tenant] = xlTimesheetFixture();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $plain = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($plain);

    test()->get(xlUrl($tenant, 'timesheet/xlsx', 'from=2026-10-12&to=2026-10-25'))->assertForbidden();

    $other = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $other);
    $names = xlRead(test()->get(xlUrl($other, 'timesheet/xlsx', 'from=2026-10-12&to=2026-10-25'))->assertOk()->getContent());
    expect(json_encode($names['sheets']))->not->toContain('Abebe')->not->toContain('Hana');
});
