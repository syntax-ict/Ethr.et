<?php

declare(strict_types=1);

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
function offlineKiosk(bool $pinRequired = true): array
{
    $tenant = createTenant();
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'kiosk_pin_required' => $pinRequired]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'employee_code' => 'K-OFF', 'kiosk_pin' => Hash::make('4821'),
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
