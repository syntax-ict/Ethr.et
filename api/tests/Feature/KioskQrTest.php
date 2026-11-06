<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Kiosk\KioskQrController;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
 * The code on a kiosk screen is the same token HR prints (QrCodeService), with
 * a lifetime of seconds and the kiosk's name inside. It can be used once in
 * all, whatever the organisation's own `qr_single_use_limit` says.
 */

function kioskQrWorld(array $settings = []): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, ...$settings]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);

    $people = collect(['one', 'two'])->map(function (string $n) use ($tenant, $branch) {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'hire_date' => '2024-01-01']);

        return createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    });

    app(CurrentTenant::class)->forget();

    return ['tenant' => $tenant, 'branch' => $branch, 'session' => $session, 'people' => $people->all()];
}

function kioskQrUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function kioskQrIssue(array $w): string
{
    return test()->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'), [], ['X-Kiosk-Token' => $w['session']->token])
        ->assertOk()
        ->json('token');
}

function kioskQrScan(array $w, int $who, string $token, string $key): TestResponse
{
    return test()->actingAs($w['people'][$who])->postJson(kioskQrUrl($w['tenant'], 'attendance/qr'), [
        'qr_token' => $token,
        'type' => 'check_in',
        'idempotency_key' => $key,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('issues the kiosk a short-lived code for its own branch', function () {
    $w = kioskQrWorld();

    $body = $this->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'), [], ['X-Kiosk-Token' => $w['session']->token])
        ->assertOk()
        ->assertJsonPath('branch_public_id', $w['branch']->public_id)
        ->assertJsonPath('ttl_seconds', KioskQrController::TTL_SECONDS)
        ->json();

    $payload = app(QrCodeService::class)->validate($body['token'], $w['tenant']->id);

    expect($payload['kiosk_session_id'])->toBe($w['session']->id)
        ->and(Carbon::parse($body['expires_at'])->diffInSeconds(now(), true))->toBeLessThanOrEqual(KioskQrController::TTL_SECONDS);
});

it('gives each request a different code', function () {
    $w = kioskQrWorld();

    expect(kioskQrIssue($w))->not->toBe(kioskQrIssue($w));
});

it('lets one person use the code, and refuses everyone after', function () {
    // The organisation's own limit is unlimited; a kiosk code is one use anyway.
    $w = kioskQrWorld(['qr_single_use_limit' => 0]);
    $token = kioskQrIssue($w);

    kioskQrScan($w, 0, $token, 'kq-1')->assertCreated();

    kioskQrScan($w, 1, $token, 'kq-2')
        ->assertStatus(422)
        ->assertJsonPath('detail', __('attendance.qr_use_limit_reached'));
});

it('does not count a retried request as a second use', function () {
    $w = kioskQrWorld();
    $token = kioskQrIssue($w);

    kioskQrScan($w, 0, $token, 'kq-retry')->assertCreated();
    kioskQrScan($w, 0, $token, 'kq-retry')->assertOk();
});

it('refuses a code once it has run out', function () {
    $w = kioskQrWorld();
    $token = kioskQrIssue($w);

    Carbon::setTestNow(now()->addSeconds(KioskQrController::TTL_SECONDS + 1));

    kioskQrScan($w, 0, $token, 'kq-late')
        ->assertStatus(422)
        ->assertJsonPath('detail', __('attendance.qr_invalid_or_expired'));
});

it('records which kiosk showed the code', function () {
    $w = kioskQrWorld();

    kioskQrScan($w, 0, kioskQrIssue($w), 'kq-meta')
        ->assertCreated();

    expect(AttendanceRecord::query()->where('idempotency_key', 'kq-meta')->value('metadata'))
        ->toMatchArray(['qr_kiosk_session_id' => $w['session']->id]);
});

it('keeps the printed code multi-use under the organisation limit', function () {
    $w = kioskQrWorld(['qr_single_use_limit' => 0]);
    app(CurrentTenant::class)->set($w['tenant']);
    $printed = app(QrCodeService::class)->generate($w['branch'], null, 30)['token'];

    kioskQrScan($w, 0, $printed, 'kq-p1')->assertCreated();
    kioskQrScan($w, 1, $printed, 'kq-p2')->assertCreated();
});

it('refuses a missing or wrong kiosk token', function () {
    $w = kioskQrWorld();

    $this->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'))->assertUnauthorized();
    $this->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'), [], ['X-Kiosk-Token' => 'nope'])->assertUnauthorized();
});

it('refuses a deactivated kiosk', function () {
    $w = kioskQrWorld();
    $w['session']->update(['status' => 'inactive']);

    $this->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'), [], ['X-Kiosk-Token' => $w['session']->token])->assertUnauthorized();
});

it('shows no code where the organisation has switched QR off', function () {
    $w = kioskQrWorld(['enabled_methods' => ['kiosk', 'web']]);

    $this->postJson(kioskQrUrl($w['tenant'], 'kiosk/qr'), [], ['X-Kiosk-Token' => $w['session']->token])->assertForbidden();
});
