<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Kiosk\KioskPass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * The shared kiosk after 2026-11-01: it shows who punched (name, job title,
 * face) and nothing else about them, lets the person say "not me", and offers
 * self-service behind the person's own PIN.
 *
 * Until then the punch answer carried the whole employee record. Codes are
 * sequential (EMP-0001, EMP-0002 …), so anyone at a lobby tablet could read
 * every colleague's email, phone and date of birth by typing codes.
 */
function selfServiceKiosk(array $employee = []): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Machine operator']);
    $person = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'position_id' => $position->id,
        'employee_code' => 'K-SELF',
        'email' => 'private@example.test',
        'phone' => '+251911000000',
        'date_of_birth' => '1990-01-01',
        'kiosk_pin' => Hash::make('4321'),
        ...$employee,
    ]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);

    return [$tenant, $session, $person];
}

function selfServiceCall(Tenant $tenant, KioskSession $session, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    app(CurrentTenant::class)->forget();

    return test()->json($method, "http://{$tenant->subdomain}.ethr.test/api/v1/kiosk{$path}", $body, [
        'X-Kiosk-Token' => $session->token,
        ...$headers,
    ]);
}

function selfServicePass(Tenant $tenant, KioskSession $session, string $pin = '4321'): string
{
    return selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => $pin])
        ->assertOk()
        ->json('pass');
}

afterEach(fn () => Carbon::setTestNow());

// ── Who punched ─────────────────────────────────────────────────────────────

it('answers a punch with the name, job title and photo, and nothing that identifies the person further', function () {
    [$tenant, $session] = selfServiceKiosk();

    $response = selfServiceCall($tenant, $session, 'POST', '/check-in', [
        'employee_code' => 'K-SELF', 'type' => 'auto', 'idempotency_key' => 'k-self-1',
    ])->assertCreated()
        ->assertJsonPath('punch', 'check_in')
        ->assertJsonPath('employee.name', fn ($name) => is_string($name) && $name !== '')
        ->assertJsonPath('employee.position', 'Machine operator')
        ->assertJsonStructure(['public_id', 'check_in', 'employee' => ['name', 'name_am', 'position', 'photo_url']]);

    $body = json_encode($response->json());
    expect($body)->not->toContain('private@example.test')
        ->and($body)->not->toContain('+251911000000')
        ->and($body)->not->toContain('1990-01-01')
        ->and($response->json('employee'))->not->toHaveKeys(['email', 'phone', 'date_of_birth', 'public_id']);
});

it('puts a punch reported as "not me" in the integrity queue without undoing it', function () {
    [$tenant, $session, $person] = selfServiceKiosk();
    $id = selfServiceCall($tenant, $session, 'POST', '/check-in', [
        'employee_code' => 'K-SELF', 'type' => 'auto', 'idempotency_key' => 'k-self-2',
    ])->json('public_id');

    selfServiceCall($tenant, $session, 'POST', "/punches/{$id}/not-me", ['punch' => 'check_in'])
        ->assertStatus(202);
    selfServiceCall($tenant, $session, 'POST', "/punches/{$id}/not-me", ['punch' => 'check_in'])
        ->assertStatus(202);

    $flags = AttendanceIntegrityFlag::withoutGlobalScopes()->where('employee_id', $person->id)->get();
    expect($flags)->toHaveCount(1)
        ->and($flags->first()->type)->toBe('kiosk_wrong_person')
        ->and($flags->first()->status)->toBe('open')
        ->and(AttendanceRecord::withoutGlobalScopes()->where('public_id', $id)->value('check_in'))->not->toBeNull();
});

it('refuses "not me" after two minutes, and from another kiosk', function () {
    [$tenant, $session] = selfServiceKiosk();
    $other = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $session->branch_id, 'status' => 'active']);
    $id = selfServiceCall($tenant, $session, 'POST', '/check-in', [
        'employee_code' => 'K-SELF', 'type' => 'auto', 'idempotency_key' => 'k-self-3',
    ])->json('public_id');

    selfServiceCall($tenant, $other, 'POST', "/punches/{$id}/not-me", ['punch' => 'check_in'])->assertNotFound();

    Carbon::setTestNow(now()->addSeconds(121));
    selfServiceCall($tenant, $session, 'POST', "/punches/{$id}/not-me", ['punch' => 'check_in'])->assertNotFound();

    expect(AttendanceIntegrityFlag::withoutGlobalScopes()->where('type', 'kiosk_wrong_person')->exists())->toBeFalse();
});

// ── Self-service ────────────────────────────────────────────────────────────

it('shows a person their own week and leave balances after their code and PIN', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $person] = selfServiceKiosk();
    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual leave', 'name_am' => 'የዓመት ፈቃድ']);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'leave_type_id' => $annual->id,
        'year' => 2026, 'entitled_days' => 16, 'used_days' => 3, 'pending_days' => 1,
    ]);
    foreach (['2026-10-27', '2026-10-20'] as $date) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => $date,
            'check_in' => Carbon::parse("{$date} 08:00", 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse("{$date} 17:00", 'Africa/Addis_Ababa')->utc(),
        ]);
    }

    $pass = selfServicePass($tenant, $session);

    selfServiceCall($tenant, $session, 'GET', '/self-service', [], ['X-Kiosk-Pass' => $pass])
        ->assertOk()
        ->assertJsonPath('today', '2026-10-28')
        ->assertJsonCount(1, 'days')
        ->assertJsonPath('days.0.date', '2026-10-27')
        ->assertJsonPath('leave_balances.0.leave_type_am', 'የዓመት ፈቃድ')
        ->assertJsonPath('leave_balances.0.remaining_days', 12)
        ->assertJsonMissingPath('employee.email');
});

it('gives one answer for an unknown code and a wrong PIN', function () {
    [$tenant, $session] = selfServiceKiosk();

    $unknown = selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'NOBODY', 'pin' => '4321'])
        ->assertUnauthorized()->json('detail');
    $wrong = selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => '0000'])
        ->assertUnauthorized()->json('detail');

    expect($unknown)->toBe($wrong);
});

it('needs a PIN even where punching does not, and says so to someone without one', function () {
    [$tenant, $session] = selfServiceKiosk(['kiosk_pin' => null]);

    selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => '1234'])
        ->assertUnprocessable();
});

it('refuses someone who has left', function () {
    [$tenant, $session] = selfServiceKiosk(['status' => EmployeeStatus::TERMINATED]);

    selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => '4321'])
        ->assertUnauthorized();
});

it('locks a person out after five wrong PINs, shared with the punch counter', function () {
    [$tenant, $session] = selfServiceKiosk();

    foreach (range(1, 5) as $_) {
        selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => '0000'])
            ->assertUnauthorized();
    }

    selfServiceCall($tenant, $session, 'POST', '/self-service', ['employee_code' => 'K-SELF', 'pin' => '4321'])
        ->assertStatus(429);
});

it('honours a pass only at the kiosk that issued it, and not after it ends', function () {
    [$tenant, $session] = selfServiceKiosk();
    $other = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $session->branch_id, 'status' => 'active']);
    $pass = selfServicePass($tenant, $session);

    selfServiceCall($tenant, $other, 'GET', '/self-service', [], ['X-Kiosk-Pass' => $pass])->assertUnauthorized();

    selfServiceCall($tenant, $session, 'DELETE', '/self-service', [], ['X-Kiosk-Pass' => $pass])->assertNoContent();
    selfServiceCall($tenant, $session, 'GET', '/self-service', [], ['X-Kiosk-Pass' => $pass])->assertUnauthorized();
});

it('never lets a pass from one organisation read another', function () {
    [$tenant, $session] = selfServiceKiosk();
    [$otherTenant, $otherSession] = selfServiceKiosk();
    $pass = selfServicePass($tenant, $session);

    selfServiceCall($otherTenant, $otherSession, 'GET', '/self-service', [], ['X-Kiosk-Pass' => $pass])->assertUnauthorized();
});

it('runs out after its lifetime', function () {
    [$tenant, $session] = selfServiceKiosk();
    $pass = selfServicePass($tenant, $session);

    Carbon::setTestNow(now()->addSeconds(KioskPass::LIFETIME + 1));

    selfServiceCall($tenant, $session, 'GET', '/self-service', [], ['X-Kiosk-Pass' => $pass])->assertUnauthorized();
});

it('lets a person change their own PIN, after typing the current one again', function () {
    [$tenant, $session, $person] = selfServiceKiosk();
    $pass = selfServicePass($tenant, $session);

    selfServiceCall($tenant, $session, 'PUT', '/self-service/pin', [
        'current_pin' => '0000', 'pin' => '2468', 'pin_confirmation' => '2468',
    ], ['X-Kiosk-Pass' => $pass])->assertUnprocessable();

    selfServiceCall($tenant, $session, 'PUT', '/self-service/pin', [
        'current_pin' => '4321', 'pin' => '2468', 'pin_confirmation' => '2468',
    ], ['X-Kiosk-Pass' => $pass])->assertNoContent();

    expect(Hash::check('2468', $person->fresh()->kiosk_pin))->toBeTrue();
});
