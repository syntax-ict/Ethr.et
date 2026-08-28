<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Control-plane operator safeguards.
 *
 * The platform console is the highest-value account in the product — it reaches
 * across every tenant and can mint a session as any tenant admin — so it carries
 * requirements the tenant-facing API does not.
 */
function platformOperator(bool $mfa = true): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => $mfa,
    ]);

    test()->actingAs($user);

    return $user;
}

function failedJobRow(string $uuid, string $displayName = 'App\\Jobs\\ExampleJob'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName]),
        'exception' => 'Exception: something broke',
        'failed_at' => now(),
    ]);
}

describe('MFA is mandatory for platform operators', function () {
    it('blocks every state-changing action when the operator has no MFA', function () {
        // Impersonation already demanded MFA at the point of use; nothing else
        // did, so a password alone could suspend a tenant or rewrite the bank
        // account every tenant pays into.
        platformOperator(mfa: false);
        $tenant = Tenant::factory()->create();

        test()->putJson('http://admin.ethr.test/api/v1/admin/platform-settings', [])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/mfa-required');

        test()->putJson("http://admin.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/status", ['status' => 'suspended'])
            ->assertForbidden();

        test()->postJson('http://admin.ethr.test/api/v1/admin/failed-jobs/retry-all')
            ->assertForbidden();

        expect($tenant->fresh()->status->value)->not->toBe('suspended');
    });

    it('still allows reads, so an un-enrolled operator can reach the screen telling them why', function () {
        platformOperator(mfa: false);

        test()->getJson('http://admin.ethr.test/api/v1/admin/tenants')->assertOk();
        test()->getJson('http://admin.ethr.test/api/v1/admin/health')->assertOk();
    });

    it('allows writes once MFA is enabled', function () {
        platformOperator(mfa: true);

        test()->postJson('http://admin.ethr.test/api/v1/admin/failed-jobs/retry-all')
            ->assertOk();
    });
});

describe('failed job dismissal', function () {
    it('drops a job that can never succeed and records what was dropped', function () {
        // Without this, an unrunnable job sat in the Attention Required banner
        // forever and the operator learned to ignore the banner.
        platformOperator();
        $uuid = (string) Str::uuid();
        failedJobRow($uuid, 'App\\Jobs\\BackupTenantJob');

        test()->deleteJson("http://admin.ethr.test/api/v1/admin/failed-jobs/{$uuid}")
            ->assertNoContent();

        expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

        // The row is gone, so the audit entry is the only surviving record of it.
        $entry = DB::table('audit_log')->where('action', 'admin.failed_job.dismissed')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->payload)->toContain('BackupTenantJob');
    });

    it('404s on an unknown job rather than reporting a successful dismissal', function () {
        platformOperator();

        test()->deleteJson('http://admin.ethr.test/api/v1/admin/failed-jobs/'.Str::uuid())
            ->assertNotFound();
    });

    it('refuses dismissal from a tenant admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

        $uuid = (string) Str::uuid();
        failedJobRow($uuid);

        test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/{$uuid}")
            ->assertForbidden();

        expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
    });

    it('audits a retry as well, which previously left no trace at all', function () {
        platformOperator();
        $uuid = (string) Str::uuid();
        failedJobRow($uuid);

        test()->postJson("http://admin.ethr.test/api/v1/admin/failed-jobs/{$uuid}/retry")->assertOk();

        expect(DB::table('audit_log')->where('action', 'admin.failed_job.retried')->exists())->toBeTrue();
    });
});

describe('cross-tenant operator lookup', function () {
    it('finds a user in any tenant and names the tenant they belong to', function () {
        $acme = Tenant::factory()->create(['name' => 'Acme Ethiopia']);
        User::factory()->create(['tenant_id' => $acme->id, 'email' => 'abebe@acme.et']);

        $other = Tenant::factory()->create(['name' => 'Other Org']);
        User::factory()->create(['tenant_id' => $other->id, 'email' => 'someone@other.et']);

        platformOperator();

        $response = test()->getJson('http://admin.ethr.test/api/v1/admin/users/search?q=abebe@acme.et')
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.email'))->toBe('abebe@acme.et')
            ->and($response->json('data.0.tenant.name'))->toBe('Acme Ethiopia');
    });

    it('never leaks a numeric primary key', function () {
        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'leak@check.et']);
        platformOperator();

        $response = test()->getJson('http://admin.ethr.test/api/v1/admin/users/search?q=leak@check.et')
            ->assertOk();

        $response->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.tenant.id');
    });

    it('refuses a term short enough to match the whole platform', function () {
        platformOperator();

        test()->getJson('http://admin.ethr.test/api/v1/admin/users/search?q=a')
            ->assertUnprocessable();
    });

    it('treats a LIKE wildcard as literal text, not as "return everyone"', function () {
        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'real@user.et']);
        platformOperator();

        $response = test()->getJson('http://admin.ethr.test/api/v1/admin/users/search?q=%25%25')
            ->assertOk();

        expect($response->json('data'))->toBeEmpty();
    });

    it('records the lookup, because cross-tenant searches are worth reviewing', function () {
        $tenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'audited@user.et']);
        platformOperator();

        test()->getJson('http://admin.ethr.test/api/v1/admin/users/search?q=audited@user.et')->assertOk();

        expect(DB::table('audit_log')->where('action', 'admin.user.searched')->exists())->toBeTrue();
    });

    it('refuses the lookup for a tenant admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/users/search?q=someone@example.et")
            ->assertForbidden();
    });
});
