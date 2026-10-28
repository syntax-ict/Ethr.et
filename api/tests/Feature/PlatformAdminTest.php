<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Jobs\BackupTenantJob;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\SystemAlertNotification;
use App\Services\Admin\SystemHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ── Super Admin Tenant Management ──

test('super admin can list tenants', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    Tenant::factory()->count(3)->create();

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
    expect($response->json('meta.total'))->toBe(4);
});

test('super admin can view tenant detail', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create(['name' => 'Acme Corp']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}");

    $response->assertOk()
        ->assertJsonPath('name', 'Acme Corp');
});

test('super admin can change tenant status', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/status", [
        'status' => 'suspended',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'suspended');

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.status_changed']);
});

test('suspending a tenant blocks it immediately, not after the resolve cache expires', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create(['status' => TenantStatus::ACTIVE, 'subdomain' => 'suspendme']);

    // Warm ResolveTenant's cache the same way a normal request would.
    test()->getJson('http://suspendme.ethr.test/api/v1/register/check-subdomain?subdomain=xyz')
        ->assertOk();

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/status", [
        'status' => 'suspended',
    ])->assertOk();

    // Without busting the cache this would still resolve as active for up to 5 minutes.
    test()->getJson('http://suspendme.ethr.test/api/v1/attendance/settings')
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-inactive');
});

test('super admin can extend trial', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => now()->addDays(7),
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/extend-trial", [
        'days' => 30,
    ]);

    $response->assertOk();
    expect($response->json('trial_ends_at'))->not->toBeNull();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.trial_extended']);
});

test('extending a trial moves the trialing subscription with it', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $end = now()->addDays(7)->startOfSecond();
    $target = Tenant::factory()->create([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => $end,
    ]);
    $subscription = Subscription::withoutGlobalScopes()->create([
        'tenant_id' => $target->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => SubscriptionStatus::TRIAL,
        'current_period_start' => now(),
        'current_period_end' => $end,
    ]);

    // Production, 2026-10-12: the console then showed "Trial ends 11 May" beside
    // "Renews 11 Apr" (console QA F19).
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/extend-trial", [
        'days' => 30,
    ])->assertOk();

    expect($subscription->fresh()->current_period_end->toDateString())
        ->toBe($end->copy()->addDays(30)->toDateString())
        ->and($target->fresh()->trial_ends_at->toDateString())
        ->toBe($end->copy()->addDays(30)->toDateString());
});

test('tenant admin cannot access admin endpoints', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants")
        ->assertForbidden();
});

// ── Tenant Backup ──

test('super admin triggering backup queues the export job and audit-logs it', function () {
    Queue::fake();

    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $target = Tenant::factory()->create();

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/backup");

    $response->assertOk()->assertJsonPath('tenant_id', $target->public_id);

    Queue::assertPushed(BackupTenantJob::class);
    test()->assertDatabaseHas('audit_log', ['action' => 'admin.tenant.backup_triggered']);

    // The actor is named by public_id: GET /admin/audit returns this payload,
    // and it carried the numeric primary key (console QA F21).
    $row = DB::table('audit_log')->where('action', 'admin.tenant.backup_triggered')->first();
    expect(json_decode((string) $row->payload, true)['triggered_by'] ?? null)->toBe($user->public_id);
});

test('tenant admin cannot trigger a backup', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/backup")
        ->assertForbidden();
});

test('BackupTenantJob exports tenant-scoped rows and notifies the requester', function () {
    Notification::fake();
    Storage::fake(config('filesystems.default'));

    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    (new BackupTenantJob($tenant->id, $admin->id))->handle();

    $files = Storage::disk(config('filesystems.default'))->allFiles("backups/{$tenant->subdomain}");
    expect($files)->toHaveCount(1);

    $payload = json_decode(Storage::disk(config('filesystems.default'))->get($files[0]), true);
    expect($payload['tenant']['public_id'])->toBe($tenant->public_id);
    expect($payload['tables']['employees'])->toHaveCount(2);
    // Sensitive/internal fields stay hidden, same as every API response.
    expect($payload['tables']['users'][0])->not->toHaveKey('password');
    expect($payload['tables']['users'][0])->not->toHaveKey('id');

    Notification::assertSentTo($admin, SystemAlertNotification::class);
});

test('BackupTenantJob notifies failure instead of throwing when export breaks', function () {
    Notification::fake();

    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk unavailable'));

    (new BackupTenantJob($tenant->id, $admin->id))->handle();

    Notification::assertSentTo(
        $admin,
        SystemAlertNotification::class,
        fn ($notification) => str_contains($notification->toArray($admin)['title'], 'failed'),
    );
});

// ── Revenue Dashboard ──

test('super admin can view revenue', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/revenue");

    $response->assertOk()
        ->assertJsonStructure([
            'mrr_cents',
            'total_tenants',
            'active_tenants',
            'trial_tenants',
            'monthly_trend',
        ]);
});

// Production, 2026-10-28: "MRR 0 ETB" beside "1 paying customer". The figure was
// this month's paid invoices, and the customer was active on a free plan.
test('recurring revenue is what active subscriptions are billed, not what was paid this month', function () {
    $home = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($user);

    $subscribe = function (TenantStatus $tenantStatus, SubscriptionStatus $status, int $price): Tenant {
        $tenant = Tenant::factory()->create(['status' => $tenantStatus]);
        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['price_cents' => $price])->id,
            'price_cents' => $price,
            'status' => $status,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    };

    $paying = $subscribe(TenantStatus::ACTIVE, SubscriptionStatus::ACTIVE, 299900);
    $subscribe(TenantStatus::ACTIVE, SubscriptionStatus::PAST_DUE, 99900);
    $subscribe(TenantStatus::ACTIVE, SubscriptionStatus::ACTIVE, 0);
    $subscribe(TenantStatus::TRIAL, SubscriptionStatus::TRIAL, 599900);

    Invoice::factory()->paid()->create(['tenant_id' => $paying->id, 'amount_cents' => 150000]);

    test()->getJson("http://{$home->subdomain}.ethr.test/api/v1/admin/revenue")
        ->assertOk()
        ->assertJsonPath('mrr_cents', 299900 + 99900)
        ->assertJsonPath('paying_tenants', 2)
        ->assertJsonPath('collected_this_month_cents', 150000)
        ->assertJsonPath('monthly_trend.11.month', now()->format('Y-m'))
        ->assertJsonPath('monthly_trend.11.revenue_cents', 150000);
});

test('an organisation that moved to a free plan stops counting as paying', function () {
    $home = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($user);

    $tenant = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);
    foreach ([299900, 0] as $price) {
        Subscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['price_cents' => $price])->id,
            'price_cents' => $price,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    test()->getJson("http://{$home->subdomain}.ethr.test/api/v1/admin/revenue")
        ->assertOk()
        ->assertJsonPath('mrr_cents', 0)
        ->assertJsonPath('paying_tenants', 0);
});

// ── System Health ──

test('super admin can view system health', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health");

    $response->assertOk()
        ->assertJsonStructure([
            'services',
            'queue',
            'failed_jobs',
        ]);

    // A number, not necessarily a float: JSON writes 84.0 as 84, which decodes
    // as an int — the CI runner's disk measured exactly that.
    expect($response->json('resources.disk_free_gb'))->toBeNumeric();
});

test('health reports no disk figure rather than failing when free space cannot be read', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    // On Plesk, open_basedir made disk_free_space('/') warn, Laravel turned the
    // warning into an exception, and every call to this endpoint answered 500
    // (console QA F2, 2026-10-12). A path that does not exist warns the same way.
    app()->instance(SystemHealthService::class, new SystemHealthService('/no/such/directory/ethr-health'));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health")
        ->assertOk()
        ->assertJsonPath('resources.disk_free_gb', null)
        ->assertJsonPath('services.database.status', 'healthy');
});

// The bar compared this request's memory with this request's own peak, so it
// read "34 MB / 34 MB" in red on production. The limit is what it can run out of.
test('health reports the memory limit, and no limit when there is none', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $previous = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '2G');
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health")
            ->assertOk()
            ->assertJsonPath('resources.php_memory_limit_mb', 2048);

        ini_set('memory_limit', '-1');
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health")
            ->assertOk()
            ->assertJsonPath('resources.php_memory_limit_mb', null);
    } finally {
        ini_set('memory_limit', (string) $previous);
    }
});

// ── Admin Audit Log ──

test('super admin can view platform audit log', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/audit");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
});

// The platform trail mixes every organisation's rows and said whose none of
// them were: a sign-in read the same in every organisation.
test('the platform audit log names the organisation each row belongs to', function () {
    $home = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($user);

    $acme = Tenant::factory()->create(['name' => 'Acme Woreda']);
    $gone = Tenant::factory()->create(['name' => 'Closed Clinic']);
    $gone->delete();

    DB::table('audit_log')->insert([
        ['tenant_id' => $acme->id, 'action' => 'user.login', 'created_at' => now()->subMinutes(3)],
        ['tenant_id' => $gone->id, 'action' => 'user.login', 'created_at' => now()->subMinutes(2)],
        ['tenant_id' => null, 'action' => 'platform.plan.updated', 'created_at' => now()->subMinute()],
    ]);

    $data = collect(test()->getJson("http://{$home->subdomain}.ethr.test/api/v1/admin/audit")
        ->assertOk()
        ->json('data'));
    $rows = $data->where('action', 'user.login')->keyBy('organisation.name');

    expect($rows['Acme Woreda']['organisation']['public_id'])->toBe($acme->public_id)
        ->and($rows['Closed Clinic']['organisation']['public_id'])->toBe($gone->public_id)
        ->and($rows['Acme Woreda'])->not->toHaveKey('tenant_id')
        ->and($data->firstWhere('action', 'platform.plan.updated')['organisation'])->toBeNull();
});

test('an organisation\'s own audit log does not name the organisation on every row', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    DB::table('audit_log')->insert(['tenant_id' => $tenant->id, 'action' => 'user.login', 'created_at' => now()]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs")
        ->assertOk()
        ->assertJsonPath('data.0.organisation', null);
});

// ── Failed Jobs ──

test('super admin can list failed jobs', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
        'exception' => 'Exception: something broke',
        'failed_at' => now(),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0'))->not->toHaveKey('id');
});

test('tenant admin cannot list failed jobs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs")
        ->assertForbidden();
});

test('retrying an unknown failed job returns 404', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/".Str::uuid().'/retry');

    $response->assertNotFound();
});

test('retrying all failed jobs when none exist reports zero', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/failed-jobs/retry-all");

    $response->assertOk()
        ->assertJsonPath('count', 0);
});

// ── Billing ──

test('tenant admin can view billing dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/billing/dashboard");

    $response->assertOk()
        ->assertJsonStructure(['plan', 'invoices']);
});

// An organisation marked its own invoices paid, under its own
// `billing.manage`: overdue suspension defeated and revenue overstated. Payment
// is by bank transfer to the provider, so the provider confirms it (audit N94).
test('an organisation cannot mark its own invoice paid', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => 'pending']);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/billing/invoices/{$invoice->public_id}/mark-paid")
        ->assertNotFound();

    expect($invoice->fresh()->status)->toBe('pending');
});

test('the platform console confirms an invoice paid', function () {
    $home = createTenant();
    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($superAdmin);
    $target = Tenant::factory()->create();
    $invoice = Invoice::factory()->create(['tenant_id' => $target->id, 'status' => 'pending']);

    test()->putJson("http://{$home->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/invoices/{$invoice->public_id}/mark-paid")
        ->assertOk()
        ->assertJsonPath('status', 'paid');

    expect($invoice->fresh()->paid_at)->not->toBeNull();
});

test('the console will not mark an invoice through another organisation', function () {
    $home = createTenant();
    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($superAdmin);
    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $invoice = Invoice::factory()->create(['tenant_id' => $owner->id, 'status' => 'pending']);

    test()->putJson("http://{$home->subdomain}.ethr.test/api/v1/admin/tenants/{$other->public_id}/invoices/{$invoice->public_id}/mark-paid")
        ->assertNotFound();

    expect($invoice->fresh()->status)->toBe('pending');
});

// ── Settings ──

test('tenant admin can view settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings");

    $response->assertOk()
        ->assertJsonStructure([
            'organization',
            'leave',
            'payroll',
            'security',
        ]);
});

test('tenant admin can update settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => [
            'session_timeout_minutes' => 30,
            'mfa_policy' => 'required',
        ],
    ]);

    $response->assertOk();
    expect($response->json('settings.session_timeout_minutes'))->toBe(30);

    $this->assertDatabaseHas('audit_log', ['action' => 'settings.updated']);
});

test('employee cannot update settings', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => ['mfa_policy' => 'required'],
    ])->assertForbidden();
});

test('settings expose fiscal-year and pagumen defaults', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings");

    $response->assertOk()
        ->assertJsonPath('payroll.fiscal_year_start_month', 1)
        ->assertJsonPath('payroll.pagumen_proration_strategy', 'full_month');
});

test('tenant admin can update fiscal-year and pagumen strategy', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => [
            'fiscal_year_start_month' => 7,
            'pagumen_proration_strategy' => 'daily_rate',
        ],
    ]);

    $response->assertOk();
    expect($response->json('settings.fiscal_year_start_month'))->toBe(7);
    expect($response->json('settings.pagumen_proration_strategy'))->toBe('daily_rate');
});

test('invalid pagumen strategy is rejected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => ['pagumen_proration_strategy' => 'weekly'],
    ])->assertStatus(422);
});

test('out-of-range fiscal-year start month is rejected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings", [
        'settings' => ['fiscal_year_start_month' => 14],
    ])->assertStatus(422);
});

// ── Audit Logs ──

test('tenant admin can view audit logs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs");

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
});

// ── Auth ──

test('admin endpoints require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/admin/tenants')
        ->assertUnauthorized();
});

test('billing requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/billing/dashboard')
        ->assertUnauthorized();
});

test('settings require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/settings')
        ->assertUnauthorized();
});
