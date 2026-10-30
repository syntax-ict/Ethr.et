<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\FeatureOverrideMode;
use App\Enums\FeatureState;
use App\Enums\UserRole;
use App\Jobs\DispatchWebhookJob;
use App\Jobs\SendWebPush;
use App\Models\Announcement;
use App\Models\ApiKey;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\OtpCode;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Notifications\MissingPunchNotification;
use App\Services\Attendance\AttendancePolicy;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Support\FeatureUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The integration and public-surface switches (docs/FEATURE-CONTROL-CENTER-DESIGN.md
 * §6, PR 3). Each is checked off and back on, because a switch that only
 * refuses proves half of what it claims: the other half is that switching it
 * back restores what the organisation had, without anyone saving anything.
 */
function switchesUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

describe('outbound webhooks', function () {
    test('a delivery is held and queued again, not failed, while delivery is off', function () {
        Http::fake(['*' => Http::response('ok', 200)]);
        $tenant = createTenant();
        $webhook = Webhook::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'url' => 'https://93.184.216.34/hooks',
            'events' => ['payroll.processed'],
            'secret' => 'shh',
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'webhook_id' => $webhook->id,
            'event' => 'payroll.processed',
            'payload' => ['event' => 'payroll.processed'],
            'attempt' => 0,
        ]);
        app(CurrentTenant::class)->forget();
        $job = new DispatchWebhookJob($webhook->id, 'payroll.processed', ['event' => 'payroll.processed'], $delivery->id, $tenant->id);

        switchFeature(Feature::WebhooksOutbound);
        Queue::fake();
        $job->handle();

        Http::assertNothingSent();
        Queue::assertPushed(DispatchWebhookJob::class, 1);
        expect($delivery->fresh()->status)->not->toBe('failed');

        switchFeature(Feature::WebhooksOutbound, FeatureState::Enabled);
        $job->handle();

        Http::assertSentCount(1);
    });
});

describe('phone notifications', function () {
    test('none is pushed while they are off, and the in-app one still arrives', function () {
        config(['webpush.public_key' => 'BPublicKeyForTests', 'webpush.private_key' => 'PrivateKeyForTests']);
        Queue::fake();
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
        PushSubscription::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.test/abc',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.test/abc'),
            'public_key' => 'p256dh-key',
            'auth_token' => 'auth-key',
        ]);

        switchFeature(Feature::WebPush);
        $user->notify(new MissingPunchNotification($employee, '2026-10-12', 'missing_check_in'));

        Queue::assertNotPushed(SendWebPush::class);
        expect($user->notifications()->count())->toBe(1);

        switchFeature(Feature::WebPush, FeatureState::Enabled);
        $user->notify(new MissingPunchNotification($employee, '2026-10-13', 'missing_check_in'));

        Queue::assertPushed(SendWebPush::class, 1);
    });
});

describe('sign-in codes by email', function () {
    test('none is sent while they are off, and the page stops offering them', function () {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'abebe@example.com', 'status' => 'active']);
        app(CurrentTenant::class)->forget();

        switchFeature(Feature::EmailSignInCodes);

        test()->getJson(switchesUrl($tenant, '/auth/tenant-context'))
            ->assertOk()
            ->assertJsonPath('tenant.sign_in.email_code', false);
        app(CurrentTenant::class)->forget();
        test()->postJson(switchesUrl($tenant, '/auth/otp/request'), ['email' => 'abebe@example.com'])
            ->assertStatus(503)
            ->assertJsonPath('detail', __('auth.otp_email_paused'));
        expect(OtpCode::where('user_id', $user->id)->count())->toBe(0);

        switchFeature(Feature::EmailSignInCodes, FeatureState::Enabled);
        app(CurrentTenant::class)->forget();
        test()->postJson(switchesUrl($tenant, '/auth/otp/request'), ['email' => 'abebe@example.com'])->assertOk();

        expect(OtpCode::where('user_id', $user->id)->count())->toBe(1);
    });
});

describe('attendance selfies', function () {
    test('no punch asks for one while they are off, and the saved rule is untouched', function () {
        $tenant = createTenant();
        AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'mobile_photo_required' => true]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $policies = app(AttendancePolicy::class);

        switchFeature(Feature::AttendanceSelfies);

        expect($policies->for($employee)->mobile_photo_required)->toBeFalse()
            ->and($policies->selfieRequired($employee, $policies->for($employee), '2026-10-29'))->toBeFalse()
            ->and(AttendanceSetting::query()->sole()->mobile_photo_required)->toBeTrue();

        switchFeature(Feature::AttendanceSelfies, FeatureState::Enabled);

        expect($policies->for($employee)->mobile_photo_required)->toBeTrue();
    });
});

describe('kiosk check-in', function () {
    test('a new punch is refused while it is off', function () {
        $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
        AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'employee_code' => 'K-SW']);
        $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $punch = function () use ($tenant, $session) {
            app(CurrentTenant::class)->forget();

            return test()->postJson(switchesUrl($tenant, '/kiosk/check-in'), [
                'employee_code' => 'K-SW',
                'type' => 'auto',
                'idempotency_key' => 'kiosk-switch-'.uniqid(),
            ], ['X-Kiosk-Token' => $session->token]);
        };

        switchFeature(Feature::KioskCheckIn);

        $punch()->assertForbidden()
            ->assertJsonPath('code', 'feature-unavailable')
            ->assertJsonPath('reason', 'platform_disabled');
        expect(AttendanceRecord::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);

        switchFeature(Feature::KioskCheckIn, FeatureState::Enabled);

        $punch()->assertSuccessful();
    });
});

describe('new organisation sign-up', function () {
    test('is closed while it is off', function () {
        switchFeature(Feature::SelfRegistration);

        test()->postJson('/api/v1/auth/register', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'feature-unavailable');

        switchFeature(Feature::SelfRegistration, FeatureState::Enabled);

        test()->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    });

    test('a rollout is off, because there is no organisation to roll out to', function () {
        switchFeature(Feature::SelfRegistration, FeatureState::Restricted);

        test()->postJson('/api/v1/auth/register', [])->assertForbidden();
    });
});

describe('SCIM provisioning', function () {
    test('the identity provider is told to retry while it is off', function () {
        $tenant = createTenant();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
        $token = 'scim-switch-'.bin2hex(random_bytes(16));
        ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => 'SCIM',
            'key_hash' => hash('sha256', $token),
            'key_prefix' => substr($token, 0, 8),
            'abilities' => ['scim'],
            'created_by' => $admin->id,
            'expires_at' => now()->addYear(),
        ]);
        app(CurrentTenant::class)->forget();

        switchFeature(Feature::Scim);

        test()->getJson('/api/v1/scim/v2/Users', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) FeatureUnavailable::RETRY_AFTER_SECONDS)
            ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');

        switchFeature(Feature::Scim, FeatureState::Enabled);
        app(CurrentTenant::class)->forget();

        test()->getJson('/api/v1/scim/v2/Users', ['Authorization' => "Bearer {$token}"])->assertOk();
    });
});

describe('attendance terminals', function () {
    test('a pushed punch is answered 503, so the terminal keeps it and sends it again', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Device::factory()->zkteco()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'webhook_token' => 'switch-token',
        ]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1042']);
        $send = function () use ($tenant) {
            app(CurrentTenant::class)->forget();

            return test()->withHeader('X-Webhook-Token', 'switch-token')
                ->postJson(switchesUrl($tenant, '/devices/webhook/zkteco'), [
                    'records' => [['pin' => '1042', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
                ]);
        };

        switchFeature(Feature::DeviceWebhooksInbound);

        $send()->assertStatus(503)->assertHeader('Retry-After', (string) FeatureUnavailable::RETRY_AFTER_SECONDS);
        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(0);

        switchFeature(Feature::DeviceWebhooksInbound, FeatureState::Enabled);

        $send()->assertOk();
        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
    });

    test('an ADMS terminal keeps its punches while it is off, and stays online', function () {
        $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'zkteco_adms',
            'serial_number' => 'SWITCH-ADMS-1',
            'connection_config' => ['mode' => 'adms'],
            'webhook_ip_allowlist' => ['127.0.0.1'],
        ]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1042']);
        $upload = function () {
            app(CurrentTenant::class)->forget();
            $at = now()->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i:s');

            return test()->call('POST', '/iclock/cdata?SN=SWITCH-ADMS-1&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "1042\t{$at}\t0\t1\t0\t0\t0\n");
        };

        switchFeature(Feature::DeviceWebhooksInbound);

        $upload()->assertStatus(503)->assertHeader('Retry-After', (string) FeatureUnavailable::RETRY_AFTER_SECONDS);
        app(CurrentTenant::class)->forget();
        test()->call('GET', '/iclock/getrequest?SN=SWITCH-ADMS-1')->assertOk();
        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(0);

        switchFeature(Feature::DeviceWebhooksInbound, FeatureState::Enabled);

        $upload()->assertOk()->assertSeeText('OK: 1');
        expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
    });
});

describe('public pages', function () {
    test('the news page says it is paused, and the organisation keeps its own choice', function () {
        $tenant = createTenant();
        Announcement::query()->create([
            'tenant_id' => $tenant->id,
            'published_by' => createUser([], $tenant)->id,
            'title' => 'Plant reopens Monday',
            'body' => 'All shifts resume.',
            'priority' => 'normal',
            'target_type' => 'all',
            'published_at' => now()->subHour(),
            'show_on_landing' => true,
        ]);
        app(CurrentTenant::class)->forget();

        switchFeature(Feature::PublicNews);

        test()->getJson(switchesUrl($tenant, '/auth/tenant-landing/news'))->assertNotFound();
        app(CurrentTenant::class)->forget();
        test()->getJson(switchesUrl($tenant, '/auth/tenant-landing/feed'))->assertOk()->assertJsonPath('announcements', []);
        app(CurrentTenant::class)->forget();
        test()->getJson(switchesUrl($tenant, '/auth/tenant-context'))
            ->assertOk()
            ->assertJsonPath('tenant.landing.sections.announcements', false)
            ->assertJsonPath('tenant.landing.paused', ['announcements']);
        expect($tenant->fresh()->landing['sections']['announcements'] ?? true)->toBeTrue();

        switchFeature(Feature::PublicNews, FeatureState::Enabled);
        app(CurrentTenant::class)->forget();

        test()->getJson(switchesUrl($tenant, '/auth/tenant-landing/news'))->assertOk()->assertJsonCount(1, 'data');
    });

    test('public vacancies leave the feed and their pages while they are off, and come back', function () {
        $tenant = createTenant();
        $vacancy = Vacancy::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Machine operator',
            'employment_type' => 'full_time',
            'description' => 'Run the line.',
            'visibility' => 'public',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        app(CurrentTenant::class)->forget();

        switchFeature(Feature::PublicVacancies);

        test()->getJson(switchesUrl($tenant, '/auth/tenant-landing/feed'))->assertOk()->assertJsonPath('vacancies', []);
        app(CurrentTenant::class)->forget();
        test()->getJson(switchesUrl($tenant, "/auth/tenant-landing/vacancies/{$vacancy->public_id}"))->assertNotFound();
        app(CurrentTenant::class)->forget();
        test()->getJson(switchesUrl($tenant, '/auth/tenant-context'))
            ->assertOk()
            ->assertJsonPath('tenant.landing.paused', ['vacancies']);

        switchFeature(Feature::PublicVacancies, FeatureState::Enabled);
        app(CurrentTenant::class)->forget();

        test()->getJson(switchesUrl($tenant, "/auth/tenant-landing/vacancies/{$vacancy->public_id}"))->assertOk();
    });

    test('a section the organisation hides is not called paused', function () {
        $tenant = createTenant();
        $tenant->update(['landing' => ['sections' => ['vacancies' => false]]]);
        app(CurrentTenant::class)->forget();

        switchFeature(Feature::PublicVacancies);

        test()->getJson(switchesUrl($tenant, '/auth/tenant-context'))
            ->assertOk()
            ->assertJsonPath('tenant.landing.paused', []);
    });
});

describe('telling the organisation', function () {
    test('/me names what the platform switched off that the organisation would otherwise have', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        switchFeature(Feature::Scim);
        switchFeature(Feature::KioskCheckIn);
        TenantFeatureOverride::query()->create([
            'tenant_id' => $tenant->id,
            'key' => Feature::KioskCheckIn->value,
            'mode' => FeatureOverrideMode::Revoke,
            'reason' => 'Abuse',
        ]);
        app(FeatureGate::class)->flush();

        test()->actingAs($user)
            ->getJson(switchesUrl($tenant, '/auth/me'))
            ->assertOk()
            ->assertJsonPath('paused_features', ['scim']);
    });
});
