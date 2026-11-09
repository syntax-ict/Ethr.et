<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A kiosk token is the whole credential of a shared terminal. It was stored
 * as written and never expired (review 2026-11-09): anyone who read the table,
 * or copied a terminal's browser storage once, punched for the organisation
 * until someone thought to regenerate it. Now only its hash is kept, and a
 * session stops working at `expires_at` until an admin renews it.
 */
function kioskLifetimeUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

afterEach(fn () => Carbon::setTestNow());

it('stores only the hash of a token it issues, and shows the token once', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $created = test()->postJson(kioskLifetimeUrl($tenant, '/kiosk-sessions'), [
        'name' => 'Lobby',
        'branch_public_id' => $branch->public_id,
        'admin_pin' => '1234',
    ])->assertCreated();

    $token = (string) $created->json('token');
    expect($token)->toHaveLength(64)
        ->and($created->json('expires_at'))->not->toBeNull();

    $row = (array) DB::table('kiosk_sessions')->where('public_id', $created->json('public_id'))->first();
    expect($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(array_values($row))->not->toContain($token);

    test()->getJson(kioskLifetimeUrl($tenant, '/kiosk-sessions/'.$created->json('public_id')))
        ->assertOk()
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('token_hash');
});

it('refuses a token whose session has expired, on every kiosk endpoint', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->expired()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    app(CurrentTenant::class)->forget();

    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/authenticate'), ['token' => $session->token])
        ->assertUnauthorized();
    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/check-in'), ['employee_code' => 'X', 'type' => 'auto', 'idempotency_key' => 'expired-1'], ['X-Kiosk-Token' => $session->token])
        ->assertUnauthorized();
    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/sync'), ['records' => [['idempotency_key' => 'expired-2', 'sealed' => 'x']]], ['X-Kiosk-Token' => $session->token])
        ->assertUnauthorized();
});

it('stops a session at the end of its period, and renewing keeps the same token working', function () {
    Carbon::setTestNow('2026-11-09 08:00:00');
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $token = (string) $session->token;
    app(CurrentTenant::class)->forget();

    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/authenticate'), ['token' => $token])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-11-09 08:00:00')->addDays((int) config('attendance.kiosk_session_days'))->addMinute());
    app(CurrentTenant::class)->forget();
    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/authenticate'), ['token' => $token])->assertUnauthorized();

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $renewed = test()->postJson(kioskLifetimeUrl($tenant, "/kiosk-sessions/{$session->public_id}/renew"))
        ->assertOk()
        ->assertJsonMissingPath('token');
    expect(Carbon::parse((string) $renewed->json('expires_at'))->isFuture())->toBeTrue();

    app(CurrentTenant::class)->forget();
    auth()->forgetGuards();
    test()->postJson(kioskLifetimeUrl($tenant, '/kiosk/authenticate'), ['token' => $token])->assertOk();
});

it('starts a new period when the token is replaced', function () {
    Carbon::setTestNow('2026-11-09 08:00:00');
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'expires_at' => now()->addDay(),
    ]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(kioskLifetimeUrl($tenant, "/kiosk-sessions/{$session->public_id}/regenerate-token"))->assertOk();

    expect($session->fresh()?->expires_at?->toDateTimeString())
        ->toBe(now()->addDays((int) config('attendance.kiosk_session_days'))->toDateTimeString());
});

it('does not let an employee renew a kiosk session', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->expired()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->postJson(kioskLifetimeUrl($tenant, "/kiosk-sessions/{$session->public_id}/renew"))->assertForbidden();

    expect($session->fresh()?->isExpired())->toBeTrue();
});
