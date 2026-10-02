<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\TenantTime;
use Illuminate\Http\UploadedFile;

/**
 * Audit N4: manual entry and CSV import stored times three hours early.
 *
 * Both read a wall-clock `H:i` in the app timezone (UTC). A 09:00 punch for an
 * Addis Ababa tenant was stored as 09:00 UTC and shown back as 12:00. The
 * entered time is now read in `tenants.timezone` and stored as the UTC
 * instant it means; the `date` column stays the local working day.
 */
function tenantTimezoneManualPost(Tenant $tenant, Employee $employee, string $key, string $in, ?string $out = null): AttendanceRecord
{
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", array_filter([
        'idempotency_key' => $key,
        'employee_public_id' => $employee->public_id,
        'date' => '2026-06-15',
        'check_in' => $in,
        'check_out' => $out,
        'reason' => 'Badge reader offline',
    ]))->assertCreated();

    return AttendanceRecord::where('employee_id', $employee->id)->latest('id')->firstOrFail();
}

test('a manual 09:00 punch in Addis Ababa is stored as 06:00 UTC', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $record = tenantTimezoneManualPost($tenant, $employee, 'tz-manual-1', '09:00', '17:30');

    expect($record->check_in->utc()->format('Y-m-d H:i'))->toBe('2026-06-15 06:00');
    expect($record->check_out->utc()->format('Y-m-d H:i'))->toBe('2026-06-15 14:30');
    expect($record->date->format('Y-m-d'))->toBe('2026-06-15');
    // Shown back in the tenant's zone, it is the time that was entered.
    expect($record->check_in->copy()->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('09:00');
});

test('manual entry reads the tenant\'s own timezone, not a fixed offset', function () {
    $tenant = createTenant(['timezone' => 'Asia/Dubai']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $record = tenantTimezoneManualPost($tenant, $employee, 'tz-manual-2', '09:00');

    expect($record->check_in->utc()->format('H:i'))->toBe('05:00');
});

test('an early-morning manual punch lands on the previous UTC day but keeps its local date', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $record = tenantTimezoneManualPost($tenant, $employee, 'tz-manual-3', '01:30', '09:00');

    expect($record->check_in->utc()->format('Y-m-d H:i'))->toBe('2026-06-14 22:30');
    expect($record->date->format('Y-m-d'))->toBe('2026-06-15');
});

test('an imported 08:30-17:30 day in Addis Ababa is stored as 05:30-14:30 UTC', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'TZ001']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", [
        'import_key' => 'tz-import-1',
        'rows' => [['employee_code' => 'TZ001', 'date' => '2026-06-15', 'check_in' => '08:30', 'check_out' => '17:30']],
    ])->assertCreated()->assertJsonPath('created', 1);

    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    expect($record->check_in->utc()->format('Y-m-d H:i'))->toBe('2026-06-15 05:30');
    expect($record->check_out->utc()->format('Y-m-d H:i'))->toBe('2026-06-15 14:30');
    expect($record->date->format('Y-m-d'))->toBe('2026-06-15');
});

test('a CRLF csv with a blank line previews every row and keeps the check-out', function () {
    // Split on "\n" alone, the blank line survived as a lone "\r" and became
    // an invalid row ("employee_code is required"). str_getcsv() already drops
    // a trailing "\r" from a cell on PHP 8.2, so the check-out itself was not
    // lost here; the assertion pins that it stays that way.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'TZ002']);

    $csv = "employee_code,date,check_in_time,check_out_time\r\nTZ002,2026-06-15,08:30,17:30\r\n\r\nTZ002,2026-06-16,08:00,17:00\r\n";

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => UploadedFile::fake()->createWithContent('excel.csv', $csv),
    ])->assertOk();

    $response->assertJsonPath('valid', 2)
        ->assertJsonPath('invalid', 0)
        ->assertJsonPath('rows.0.check_out_time', '17:30')
        ->assertJsonPath('rows.1.check_out_time', '17:00');
});

test('a csv with carriage-return line endings is read as rows, not one line', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'TZ004']);

    $csv = "employee_code,date,check_in_time,check_out_time\rTZ004,2026-06-15,08:30,17:30\r";

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => UploadedFile::fake()->createWithContent('mac.csv', $csv),
    ])->assertOk()
        ->assertJsonPath('errors', [])
        ->assertJsonPath('valid', 1);
});

test('a csv saved with a UTF-8 byte-order mark is read from its first column', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'TZ003']);

    $csv = "\xEF\xBB\xBFemployee_code,date,check_in_time,check_out_time\r\nTZ003,2026-06-15,08:30,17:30\r\n";

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => UploadedFile::fake()->createWithContent('excel-utf8.csv', $csv),
    ])->assertOk()
        ->assertJsonPath('errors', [])
        ->assertJsonPath('valid', 1)
        ->assertJsonPath('rows.0.employee_code', 'TZ003');
});

test('an unknown stored timezone falls back to Addis Ababa instead of failing', function () {
    expect(TenantTime::zone(new Tenant(['timezone' => 'Not/AZone'])))->toBe('Africa/Addis_Ababa');
    expect(TenantTime::zone(new Tenant(['timezone' => 'Asia/Dubai'])))->toBe('Asia/Dubai');
    expect(TenantTime::zone(null))->toBe('Africa/Addis_Ababa');
});
