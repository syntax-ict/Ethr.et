<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Kiosk\KioskOfflineSeal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * A kiosk through a power cut or a dropped connection: punches sealed with
 * the kiosk's public key wait on the terminal and are sent when the
 * connection returns. The server opens them, holds them to the live rules
 * (PIN, employee, time window) and flags what the kiosk clock cannot prove.
 */
function offlineKioskUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** Seal as the terminal does: RSA-OAEP (SHA-1) over the punch's JSON. */
function sealPunch(string $publicKeyB64, array $punch): string
{
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicKeyB64, 64, "\n")."-----END PUBLIC KEY-----\n";
    openssl_public_encrypt((string) json_encode($punch), $cipher, $pem, OPENSSL_PKCS1_OAEP_PADDING);

    return base64_encode($cipher);
}

/** @return array{Tenant, KioskSession, array<string, mixed>} */
function offlineKiosk(bool $pinRequired = true, array $employee = []): array
{
    $tenant = createTenant();
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'kiosk_pin_required' => $pinRequired]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'employee_code' => 'K-OFF', 'kiosk_pin' => Hash::make('4821'),
        ...$employee,
    ]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
    app(CurrentTenant::class)->forget();

    $config = test()->postJson(offlineKioskUrl($tenant, '/kiosk/authenticate'), ['token' => $session->token])
        ->assertOk()
        ->json('offline');
    app(CurrentTenant::class)->forget();

    return [$tenant, $session, $config];
}

function syncKiosk(Tenant $tenant, KioskSession $session, array $records): TestResponse
{
    app(CurrentTenant::class)->forget();

    return test()->postJson(offlineKioskUrl($tenant, '/kiosk/sync'), ['records' => $records], ['X-Kiosk-Token' => $session->token]);
}

afterEach(fn () => Carbon::setTestNow());

it('gives the terminal a key and a grant, and never the private key', function () {
    [, $session, $config] = offlineKiosk();

    expect($config)->toHaveKeys(['public_key', 'grant', 'issued_at', 'server_time'])
        ->and($config['public_key'])->not->toContain('PRIVATE');
    expect($session->fresh()->offline_private_key)->not->toContain('BEGIN');
});

it('records a sealed punch made during an outage, at the moment it was made', function () {
    Carbon::setTestNow('2026-10-13 05:00:00');
    [$tenant, $session, $config] = offlineKiosk();

    // The power went out; the punch was made an hour later and sent at 07:00.
    Carbon::setTestNow('2026-10-13 07:00:00');
    $sealed = sealPunch($config['public_key'], [
        'c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => '2026-10-13T06:00:00Z', 'k' => 'kiosk-off-1',
    ]);

    syncKiosk($tenant, $session, [['idempotency_key' => 'kiosk-off-1', 'sealed' => $sealed, 'grant' => $config['grant']]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'created');

    $record = AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'kiosk-off-1')->sole();
    expect($record->check_in->utc()->format('H:i'))->toBe('06:00')
        ->and(AttendanceIntegrityFlag::withoutGlobalScopes()->where('attendance_record_id', $record->id)->pluck('type')->all())
        ->not->toContain('unverified_offline')->not->toContain('clock_suspect');

    // Sent again after a retry: the same punch, not a second one.
    syncKiosk($tenant, $session, [['idempotency_key' => 'kiosk-off-1', 'sealed' => $sealed, 'grant' => $config['grant']]])
        ->assertJsonPath('results.0.status', 'duplicate');
});

it('still records a punch made offline when kiosk check-in is switched off before it syncs', function () {
    // A record captured while the feature was on is never discarded
    // (docs/FEATURE-CONTROL-CENTER-DESIGN.md §6): the switch refuses new
    // punches at the kiosk, not ones already waiting on the terminal.
    Carbon::setTestNow('2026-10-13 07:00:00');
    [$tenant, $session, $config] = offlineKiosk();
    $sealed = sealPunch($config['public_key'], [
        'c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => '2026-10-13T06:00:00Z', 'k' => 'kiosk-off-switched',
    ]);

    switchFeature(Feature::KioskCheckIn);

    syncKiosk($tenant, $session, [['idempotency_key' => 'kiosk-off-switched', 'sealed' => $sealed, 'grant' => $config['grant']]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'created');
});

it('refuses, while kiosk check-in is off, an offline punch with no grant to show when it was made', function () {
    // Sync skipped the switch entirely before 2026-11-09: a token and the key
    // start hands out recorded punches for an organisation that had turned
    // kiosks off. A punch is still kept when its grant was issued while the
    // feature was on (the test above); one without a grant is not.
    [$tenant, $session, $config] = offlineKiosk();
    switchFeature(Feature::KioskCheckIn);

    $punch = fn (string $key): string => sealPunch($config['public_key'], [
        'c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => $key,
    ]);

    syncKiosk($tenant, $session, [
        ['idempotency_key' => 'off-no-grant', 'sealed' => $punch('off-no-grant')],
        ['idempotency_key' => 'off-bad-grant', 'sealed' => $punch('off-bad-grant'), 'grant' => 'forged'],
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.detail', __('kiosk.offline_switched_off'))
        ->assertJsonPath('results.1.status', 'error')
        ->assertJsonPath('results.1.detail', __('kiosk.offline_switched_off'));

    expect(AttendanceRecord::withoutGlobalScopes()->whereIn('idempotency_key', ['off-no-grant', 'off-bad-grant'])->exists())->toBeFalse();
});

it('issues no offline grant while kiosk check-in is off', function () {
    [$tenant, $session] = offlineKiosk();
    switchFeature(Feature::KioskCheckIn);
    app(CurrentTenant::class)->forget();

    test()->postJson(offlineKioskUrl($tenant, '/kiosk/authenticate'), ['token' => $session->token])
        ->assertOk()
        ->assertJsonPath('offline', null);
});

it('refuses the whole sync when the kiosk method is off at its branch', function () {
    [$tenant, $session, $config] = offlineKiosk();
    AttendanceSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update([
        'enabled_methods' => json_encode(array_values(array_diff(AttendanceSetting::defaults()['enabled_methods'], ['kiosk']))),
    ]);

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'method-off',
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->toIso8601String(), 'k' => 'method-off']),
        'grant' => $config['grant'],
    ]])->assertForbidden()
        ->assertJsonPath('detail', __('attendance.method_disabled'));

    expect(AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'method-off')->exists())->toBeFalse();
});

it('checks the PIN at sync as it would have been checked live', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'bad-pin',
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '0000', 't' => 'check_in', 'at' => now()->toIso8601String(), 'k' => 'bad-pin']),
        'grant' => $config['grant'],
    ]])->assertOk()->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.detail', __('kiosk.invalid_pin'));

    expect(AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'bad-pin')->exists())->toBeFalse();
});

it('refuses anything that was not sealed with this kiosk key', function () {
    [$tenant, $session] = offlineKiosk();
    [, , $otherConfig] = offlineKiosk();

    syncKiosk($tenant, $session, [
        ['idempotency_key' => 'junk', 'sealed' => base64_encode('{"c":"K-OFF"}')],
        // Sealed for another kiosk: this kiosk's private key cannot open it.
        ['idempotency_key' => 'foreign', 'sealed' => sealPunch($otherConfig['public_key'], [
            'c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->toIso8601String(), 'k' => 'foreign',
        ])],
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.1.status', 'error');
});

it('flags a punch the kiosk clock cannot prove', function () {
    Carbon::setTestNow('2026-10-13 05:00:00');
    [$tenant, $session, $config] = offlineKiosk(pinRequired: false);
    Carbon::setTestNow('2026-10-13 07:00:00');

    syncKiosk($tenant, $session, [
        // Claimed before the kiosk last spoke to the server: the clock was wound back.
        ['idempotency_key' => 'before', 'grant' => $config['grant'], 'sealed' => sealPunch($config['public_key'], [
            'c' => 'K-OFF', 'p' => null, 't' => 'check_in', 'at' => '2026-10-12T06:00:00Z', 'k' => 'before',
        ])],
        // No grant at all.
        ['idempotency_key' => 'nogrant', 'sealed' => sealPunch($config['public_key'], [
            'c' => 'K-OFF', 'p' => null, 't' => 'check_in', 'at' => '2026-10-11T06:00:00Z', 'k' => 'nogrant',
        ])],
    ])->assertOk();

    $types = fn (string $key) => AttendanceIntegrityFlag::withoutGlobalScopes()
        ->whereHas('record', fn ($q) => $q->withoutGlobalScopes()->where('idempotency_key', $key))
        ->pluck('type')->all();

    expect($types('before'))->toContain('clock_suspect')
        ->and($types('nogrant'))->toContain('unverified_offline');
});

it('refuses a sync without a valid kiosk token', function () {
    [$tenant] = offlineKiosk();

    test()->postJson(offlineKioskUrl($tenant, '/kiosk/sync'), ['records' => [['idempotency_key' => 'x', 'sealed' => 'x']]], ['X-Kiosk-Token' => 'nope'])
        ->assertUnauthorized();
});

it('opens only what it sealed', function () {
    [, $session, $config] = offlineKiosk();
    $seal = app(KioskOfflineSeal::class);

    $punch = ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_out', 'at' => '2026-10-13T06:00:00Z', 'k' => 'k1'];
    expect($seal->open($session->fresh(), sealPunch($config['public_key'], $punch)))->toBe($punch)
        ->and($seal->open($session->fresh(), sealPunch($config['public_key'], [...$punch, 't' => 'teleport'])))->toBeNull();
});

it('lets the server decide an offline punch the kiosk sent as auto', function () {
    // The terminal sends `auto` unless the person picked (2026-10-28): an
    // arrival and a departure made through one outage end as one day.
    Carbon::setTestNow('2026-10-13 05:00:00');
    [$tenant, $session, $config] = offlineKiosk();

    Carbon::setTestNow('2026-10-13 15:00:00');
    $in = sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'auto', 'at' => '2026-10-13T05:30:00Z', 'k' => 'kiosk-auto-in']);
    $out = sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'auto', 'at' => '2026-10-13T14:00:00Z', 'k' => 'kiosk-auto-out']);

    syncKiosk($tenant, $session, [
        ['idempotency_key' => 'kiosk-auto-in', 'sealed' => $in, 'grant' => $config['grant']],
        ['idempotency_key' => 'kiosk-auto-out', 'sealed' => $out, 'grant' => $config['grant']],
    ])->assertOk()
        ->assertJsonPath('results.0.status', 'created')
        ->assertJsonPath('results.1.status', 'created');

    $record = AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'kiosk-auto-in')->sole();
    expect($record->check_out?->utc()->format('H:i'))->toBe('14:00');
});

/** @return list<array<string, mixed>> */
function refusedPunchAudits(): array
{
    return DB::table('audit_log')->where('action', 'kiosk.offline_punch_refused')->orderBy('id')->get()
        ->map(fn ($row) => [...(array) $row, 'payload' => json_decode((string) $row->payload, true)])
        ->all();
}

it('says a refusal that can never be accepted is final, and leaves one audit entry for it', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'unknown-code',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'NOBODY', 'p' => '4821', 't' => 'check_in', 'at' => now()->toIso8601String(), 'k' => 'unknown-code']),
    ]])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.final', true);

    $audits = refusedPunchAudits();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]['payload']['reason'])->toBe('employee_not_found')
        ->and($audits[0]['payload']['employee_code'])->toBe('NOBODY')
        ->and($audits[0]['payload']['idempotency_key'])->toBe('unknown-code')
        ->and($audits[0]['payload'])->toHaveKey('captured_at')
        ->and($audits[0]['tenant_id'])->toBe($tenant->id)
        ->and(json_encode($audits[0]))->not->toContain('4821');
});

it('treats a wrong PIN as final, audits it, and never writes the PIN', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'wrong-pin',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '0000', 't' => 'check_in', 'at' => now()->toIso8601String(), 'k' => 'wrong-pin']),
    ]])->assertOk()->assertJsonPath('results.0.final', true);

    $audits = refusedPunchAudits();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]['payload']['reason'])->toBe('invalid_pin')
        ->and($audits[0]['payload']['employee_code'])->toBe('K-OFF')
        ->and(json_encode($audits[0]))->not->toContain('0000');
});

it('treats a punch outside the window and one it cannot read as final', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [
        ['idempotency_key' => 'stale', 'grant' => $config['grant'], 'sealed' => sealPunch($config['public_key'], [
            'c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->subDays(30)->toIso8601String(), 'k' => 'stale',
        ])],
        ['idempotency_key' => 'junk', 'sealed' => base64_encode('{"c":"K-OFF"}')],
    ])->assertOk()
        ->assertJsonPath('results.0.final', true)
        ->assertJsonPath('results.1.final', true);

    expect(array_column(array_column(refusedPunchAudits(), 'payload'), 'reason'))->toBe(['outside_window', 'unreadable']);
});

it('keeps a check-out whose check-in has not landed yet, and writes no refusal entry', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'no-open',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_out', 'at' => now()->subMinute()->toIso8601String(), 'k' => 'no-open']),
    ]])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.final', false);

    expect(refusedPunchAudits())->toBeEmpty();
});

it('treats a punch by an employee who has left as final and audits it', function () {
    [$tenant, $session, $config] = offlineKiosk(employee: [
        'status' => EmployeeStatus::RESIGNED,
        'termination_date' => now()->subMonths(2)->toDateString(),
    ]);

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'left',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => 'left']),
    ]])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.final', true);

    expect(refusedPunchAudits())->toHaveCount(1)
        ->and(refusedPunchAudits()[0]['payload']['reason'])->toBe('refused');
});

it('keeps a punch the engine failed on for a passing reason, and writes no refusal entry', function () {
    [$tenant, $session, $config] = offlineKiosk();
    // The engine is final, so the failure comes from underneath it: a write that throws.
    Event::listen('eloquent.creating: '.AttendanceRecord::class, fn () => throw new RuntimeException('database went away'));

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'boom',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => 'boom']),
    ]])->assertOk()
        ->assertJsonPath('results.0.status', 'error')
        ->assertJsonPath('results.0.detail', __('kiosk.offline_failed'))
        ->assertJsonPath('results.0.final', false);

    expect(refusedPunchAudits())->toBeEmpty();
});

it('says a PIN lock is not final, and a check made while locked adds no refusal entry', function () {
    [$tenant, $session, $config] = offlineKiosk();
    $punch = fn (string $pin, string $key): array => [
        'idempotency_key' => $key,
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => $pin, 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => $key]),
    ];

    syncKiosk($tenant, $session, array_map(fn (int $i) => $punch('0000', "lock-{$i}"), range(1, 5)))->assertOk();
    $auditsBefore = count(refusedPunchAudits());

    syncKiosk($tenant, $session, [$punch('4821', 'while-locked')])->assertOk()
        ->assertJsonPath('results.0.detail', __('kiosk.pin_locked'))
        ->assertJsonPath('results.0.final', false);

    expect(count(refusedPunchAudits()))->toBe($auditsBefore);
});

it('writes no refusal entry for a punch that is recorded', function () {
    [$tenant, $session, $config] = offlineKiosk();

    syncKiosk($tenant, $session, [[
        'idempotency_key' => 'fine',
        'grant' => $config['grant'],
        'sealed' => sealPunch($config['public_key'], ['c' => 'K-OFF', 'p' => '4821', 't' => 'check_in', 'at' => now()->subMinute()->toIso8601String(), 'k' => 'fine']),
    ]])->assertOk()
        ->assertJsonPath('results.0.status', 'created')
        ->assertJsonMissingPath('results.0.final');

    expect(refusedPunchAudits())->toBeEmpty();
});
