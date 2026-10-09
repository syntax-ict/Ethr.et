<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\KioskSession;
use Illuminate\Support\Facades\Hash;

/*
 * The kiosk lock screen asks for the admin PIN set when the kiosk was
 * registered. Until 2026-10-09 it accepted any four digits: the PIN was hashed
 * and stored and nothing ever compared it (redundancy audit R1). These pin the
 * server-side check the lock screen now calls.
 */

/** @return array{host: string, session: KioskSession} */
function kioskExitSetup(string $pin = '4821'): array
{
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'admin_pin' => Hash::make($pin),
    ]);

    return ['host' => "{$tenant->subdomain}.ethr.test", 'session' => $session];
}

it('lets the terminal leave kiosk mode with the registered admin PIN, and audits it', function () {
    ['host' => $host, 'session' => $session] = kioskExitSetup();

    $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => $session->token, 'admin_pin' => '4821'])
        ->assertNoContent();

    expect(AuditLog::withoutGlobalScopes()->where('action', 'kiosk.exited')->where('tenant_id', $session->tenant_id)->count())
        ->toBe(1);
});

it('refuses any other PIN, naming the field and nothing else', function () {
    ['host' => $host, 'session' => $session] = kioskExitSetup();

    $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => $session->token, 'admin_pin' => '0000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['admin_pin' => __('kiosk.invalid_admin_pin')]);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'kiosk.exited')->exists())->toBeFalse();
});

it('refuses an unknown or deactivated kiosk token as unauthorised', function () {
    ['host' => $host, 'session' => $session] = kioskExitSetup();

    $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => 'not-a-kiosk-token', 'admin_pin' => '4821'])
        ->assertUnauthorized();

    $session->update(['status' => 'inactive']);

    $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => $session->token, 'admin_pin' => '4821'])
        ->assertUnauthorized();
});

it('stops guessing after five tries a minute on one kiosk', function () {
    ['host' => $host, 'session' => $session] = kioskExitSetup();

    foreach (range(1, 5) as $i) {
        $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => $session->token, 'admin_pin' => sprintf('%04d', $i)])
            ->assertUnprocessable();
    }

    // The sixth is refused before the PIN is compared, even the right one.
    $this->postJson("http://{$host}/api/v1/kiosk/exit", ['token' => $session->token, 'admin_pin' => '4821'])
        ->assertTooManyRequests();
});
