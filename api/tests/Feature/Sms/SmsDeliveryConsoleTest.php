<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The SMS delivery page of the platform console: who may use it, what it
 * never reveals, and the order it insists on — configure, test, then switch on.
 */
const SMS_ADMIN = 'http://admin.ethr.test/api/v1/admin/sms';

const SMS_SECRET = 'sk-live-0123456789abcdef';

function smsOperator(bool $mfa = true): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => $mfa,
    ]);

    test()->actingAs($user);

    return $user;
}

/** @return array<string, mixed> */
function gatewayPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Aggregator',
        'endpoint' => 'https://api.aggregator.example/sms/send',
        'method' => 'json',
        'auth_type' => 'header',
        'auth_header' => 'KEY',
        'auth_secret' => SMS_SECRET,
        'recipient_field' => 'to',
        'message_field' => 'message',
        'sender_field' => 'sender',
        'sender_id' => 'ETHR',
        'number_format' => 'international',
        'success_json_path' => 'status',
        'success_json_value' => 'success',
    ], $overrides);
}

beforeEach(function () {
    RateLimiter::clear('sms-test');
});

describe('authorization', function () {
    it('lets a platform super admin with MFA read the page', function () {
        smsOperator();

        test()->getJson(SMS_ADMIN)->assertOk()->assertJsonPath('enabled', false);
    });

    it('refuses a super admin without MFA', function () {
        smsOperator(mfa: false);

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertForbidden();
    });

    it('refuses a tenant admin', function () {
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->actingAs($admin);

        $status = test()->getJson(SMS_ADMIN)->status();

        expect($status)->toBeIn([403, 404]);
        expect(SmsGateway::query()->count())->toBe(0);
    });

    it('is not reachable from an organisation\'s host', function () {
        // As production runs it: hostnames name the tenant.
        config(['app.domain' => 'ethr.et']);
        smsOperator();
        $tenant = createTenant();

        $status = test()->getJson("http://{$tenant->subdomain}.ethr.et/api/v1/admin/sms")->status();

        expect($status)->toBeIn([403, 404]);
    });
});

describe('secrets', function () {
    it('is never returned, only whether one is set', function () {
        smsOperator();

        $saved = test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        $read = test()->getJson(SMS_ADMIN)->assertOk();

        foreach ([$saved, $read] as $response) {
            expect($response->getContent())->not->toContain(SMS_SECRET);
            $response->assertJsonPath('primary.secret_set', true)
                ->assertJsonMissingPath('primary.auth_secret');
        }
    });

    it('is stored encrypted', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();

        $raw = DB::table('sms_gateways')->value('auth_secret');

        expect($raw)->not->toContain(SMS_SECRET)
            ->and(SmsGateway::query()->first()->auth_secret)->toBe(SMS_SECRET);
    });

    it('is kept when the field is left empty, and replaced when filled', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload(['auth_secret' => null, 'label' => 'Renamed']))->assertOk();
        expect(SmsGateway::query()->first()->auth_secret)->toBe(SMS_SECRET);

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload(['auth_secret' => 'sk-live-new-value-9876']))->assertOk();
        expect(SmsGateway::query()->first()->auth_secret)->toBe('sk-live-new-value-9876');
    });

    it('is required when the authentication needs one and none is stored', function () {
        smsOperator();

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload(['auth_secret' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('auth_secret');
    });

    it('never reaches the audit log', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();

        $entries = AuditLog::withoutGlobalScopes()->where('action', 'platform.sms_settings_updated')->get();

        expect($entries)->not->toBeEmpty();
        foreach ($entries as $entry) {
            expect(json_encode($entry->payload))->not->toContain(SMS_SECRET);
        }
        expect($entries->first()->payload['secret_replaced'])->toBeTrue();
    });

    it('is redacted from the test response shown in the console', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'error', 'echo' => SMS_SECRET], 401)]);

        $response = test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();

        expect($response->getContent())->not->toContain(SMS_SECRET);
        expect(test()->getJson(SMS_ADMIN)->getContent())->not->toContain(SMS_SECRET);
    });

    it('refuses an endpoint on an internal address', function () {
        smsOperator();

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload(['endpoint' => 'http://127.0.0.1/send']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');
    });
});

describe('test, then switch on', function () {
    it('cannot switch SMS on before the primary gateway is verified', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();

        test()->putJson(SMS_ADMIN.'/settings', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        expect(SmsSetting::current()->enabled)->toBeFalse();
    });

    it('verifies a gateway only on a successful test send', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();

        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'invalid sender'], 200)
            ->push(['status' => 'success'], 200)]);

        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('verified', false);

        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('verified', true);

        Http::assertSent(fn (Request $r) => $r->hasHeader('KEY', SMS_SECRET) && $r['sender'] === 'ETHR');

        test()->putJson(SMS_ADMIN.'/settings', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('available', true);
    });

    it('clears verification and switches SMS off when the connection changes', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();
        test()->putJson(SMS_ADMIN.'/settings', ['enabled' => true])->assertOk();

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload([
            'auth_secret' => null,
            'endpoint' => 'https://api.aggregator.example/v2/send',
        ]))
            ->assertOk()
            ->assertJsonPath('primary.verified', false)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('available', false);
    });

    it('keeps verification when only the label changes', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();

        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload(['auth_secret' => null, 'label' => 'New name']))
            ->assertOk()
            ->assertJsonPath('primary.verified', true);
    });

    it('refuses a landline as the test number', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake();

        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0111223344'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        Http::assertNothingSent();
    });

    it('limits how often a test can be sent', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'success'])]);

        foreach (range(1, 10) as $_) {
            test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();
        }

        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertStatus(429);
    });

    it('turns SMS off when the primary gateway is removed', function () {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();
        test()->putJson(SMS_ADMIN.'/settings', ['enabled' => true])->assertOk();

        test()->deleteJson(SMS_ADMIN.'/gateways/primary')
            ->assertOk()
            ->assertJsonPath('primary', null)
            ->assertJsonPath('enabled', false);
    });
});

describe('what the sign-in page offers', function () {
    function verifiedAndOn(int $cap = 500): void
    {
        smsOperator();
        test()->putJson(SMS_ADMIN.'/gateways/primary', gatewayPayload())->assertOk();
        Http::fake(['*' => Http::response(['status' => 'success'])]);
        test()->postJson(SMS_ADMIN.'/gateways/primary/test', ['phone' => '0911223344'])->assertOk();
        test()->putJson(SMS_ADMIN.'/settings', ['enabled' => true, 'daily_cap' => $cap])->assertOk();
    }

    it('offers text-message codes once SMS is on, and email codes regardless', function () {
        config(['mail.default' => 'smtp']);
        $tenant = createTenant();
        $context = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-context";

        test()->getJson($context)
            ->assertJsonPath('tenant.sign_in.otp', false)
            ->assertJsonPath('tenant.sign_in.email_code', true);

        verifiedAndOn();
        app('auth')->forgetGuards();

        test()->getJson($context)
            ->assertJsonPath('tenant.sign_in.otp', true)
            ->assertJsonPath('tenant.sign_in.email_code', true);
    });

    it('falls back to email codes alone once the daily cap is reached', function () {
        config(['mail.default' => 'smtp']);
        $tenant = createTenant();

        // The test send counts, so a cap of one is already reached.
        verifiedAndOn(cap: 1);
        app('auth')->forgetGuards();

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-context")
            ->assertJsonPath('tenant.sign_in.otp', false)
            ->assertJsonPath('tenant.sign_in.email_code', true);
    });

    it('refuses a landline on the text-message code path with a clear message', function () {
        verifiedAndOn();
        app('auth')->forgetGuards();
        $tenant = createTenant();

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/request", ['phone' => '0111223344'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    });
});
