<?php

declare(strict_types=1);

use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\EmployeeNumbering;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Typing the number alone at the kiosk. The pad has only digits and most codes
 * have letters (EMP-0012), so every punch meant the tablet's own keyboard. A
 * bare number now stands for the full code in the organisation's format
 * (KioskEmployeeCode); a number that could mean two people is refused.
 */
function numberKiosk(array $settings = []): array
{
    $tenant = createTenant(['settings' => $settings]);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);

    return [$tenant, $session, $branch];
}

function numberKioskPerson(Tenant $tenant, Branch $branch, string $code, string $name = 'Abebe Kebede'): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => $code,
        'name' => $name,
        'kiosk_pin' => Hash::make('4321'),
    ]);
}

function numberKioskPunch(Tenant $tenant, KioskSession $session, string $typed, string $key): TestResponse
{
    app(CurrentTenant::class)->forget();

    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => $typed, 'type' => 'auto', 'idempotency_key' => $key,
    ], ['X-Kiosk-Token' => $session->token]);
}

it('turns a typed number into the full code in the organisation format', function () {
    $tenant = createTenant(['settings' => [EmployeeNumbering::SETTING => 'MIN-{SEQ:4}/A']]);
    $numbering = app(EmployeeNumbering::class);

    expect($numbering->fromNumber($tenant, '12'))->toBe('MIN-0012/A')
        ->and($numbering->fromNumber($tenant, '0012'))->toBe('MIN-0012/A')
        ->and($numbering->fromNumber($tenant, '123456'))->toBe('MIN-123456/A')
        ->and($numbering->fromNumber($tenant, '0'))->toBeNull()
        ->and($numbering->fromNumber($tenant, 'MIN-0012/A'))->toBeNull()
        ->and($numbering->fromNumber($tenant, '123456789'))->toBeNull()
        ->and($numbering->shape($tenant))->toBe(['prefix' => 'MIN-', 'width' => 4, 'suffix' => '/A']);
});

it('punches the person whose code the number stands for', function () {
    [$tenant, $session, $branch] = numberKiosk();
    numberKioskPerson($tenant, $branch, 'EMP-0012');

    numberKioskPunch($tenant, $session, '12', 'num-1')
        ->assertCreated()
        ->assertJsonPath('employee.name', 'Abebe Kebede');

    // With the leading zeros, the same person: a second punch the same day.
    numberKioskPunch($tenant, $session, '0012', 'num-2')
        ->assertSuccessful()
        ->assertJsonPath('employee.name', 'Abebe Kebede');
});

it('still takes a full code, including one outside the format', function () {
    [$tenant, $session, $branch] = numberKiosk();
    numberKioskPerson($tenant, $branch, 'A12-B', 'Imported Person');

    numberKioskPunch($tenant, $session, 'A12-B', 'num-full')
        ->assertCreated()
        ->assertJsonPath('employee.name', 'Imported Person');
});

it('refuses a number that is one person code and stands for another', function () {
    [$tenant, $session, $branch] = numberKiosk();
    numberKioskPerson($tenant, $branch, '12', 'Imported Twelve');
    numberKioskPerson($tenant, $branch, 'EMP-0012', 'Format Twelve');

    numberKioskPunch($tenant, $session, '12', 'num-ambiguous')
        ->assertStatus(422)
        ->assertJsonPath('detail', __('kiosk.code_ambiguous'));

    // The full code is not ambiguous.
    numberKioskPunch($tenant, $session, 'EMP-0012', 'num-exact')
        ->assertCreated()
        ->assertJsonPath('employee.name', 'Format Twelve');
});

it('never reaches another organisation by number', function () {
    [$tenant, $session] = numberKiosk();
    [$other, , $otherBranch] = numberKiosk();
    numberKioskPerson($other, $otherBranch, 'EMP-0012');

    numberKioskPunch($tenant, $session, '12', 'num-cross')->assertNotFound();
});

it('opens self-service by number', function () {
    [$tenant, $session, $branch] = numberKiosk();
    numberKioskPerson($tenant, $branch, 'EMP-0007');
    app(CurrentTenant::class)->forget();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/self-service", [
        'employee_code' => '7', 'pin' => '4321',
    ], ['X-Kiosk-Token' => $session->token])
        ->assertOk()
        ->assertJsonStructure(['pass']);
});

it('records an offline punch typed as a number', function () {
    [$tenant, $session, $branch] = numberKiosk();
    numberKioskPerson($tenant, $branch, 'EMP-0031');
    app(CurrentTenant::class)->forget();

    $config = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/authenticate", ['token' => $session->token])
        ->assertOk()
        ->assertJsonPath('settings.code_format', ['prefix' => 'EMP-', 'width' => 4, 'suffix' => ''])
        ->json('offline');

    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($config['public_key'], 64, "\n")."-----END PUBLIC KEY-----\n";
    openssl_public_encrypt((string) json_encode([
        'c' => '31', 'p' => null, 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => 'num-offline',
    ]), $cipher, $pem, OPENSSL_PKCS1_OAEP_PADDING);

    app(CurrentTenant::class)->forget();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/sync", ['records' => [[
        'idempotency_key' => 'num-offline', 'sealed' => base64_encode($cipher), 'grant' => $config['grant'],
    ]]], ['X-Kiosk-Token' => $session->token])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'created');
});
