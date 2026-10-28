<?php

declare(strict_types=1);

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Jobs\SendInvitationJob;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\OtpCode;
use App\Models\SmsGateway;
use App\Models\SmsSend;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Auth\ActivationCodeRefused;
use App\Services\Auth\ActivationCodeService;
use App\Services\Auth\OtpService;
use App\Services\CurrentTenant;
use App\Services\Invitations\InvitationDispatcher;
use App\Services\Sms\ConfiguredSmsSender;
use App\Services\UserProvisioningService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Invitations for many people at once (InvitationDispatcher): queued, spaced
 * out, tracked, reminded; by email now, by text once the platform turns text
 * invitations on (owner decisions, 2026-10-28).
 */
function invitee(Tenant $tenant, array $attributes = []): User
{
    return createUser(['role' => UserRole::EMPLOYEE, 'status' => 'invited', 'invited_at' => now(), ...$attributes], $tenant);
}

/** Run one queued invitation the way a worker does: with no tenant resolved. */
function runInvitation(InvitationSend $send): SendInvitationJob
{
    app(CurrentTenant::class)->forget();

    $job = (new SendInvitationJob($send->tenant_id, $send->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

/** A verified console gateway, SMS on, and text invitations on. */
function textInvitationsOn(int $share = 30, int $cap = 500): void
{
    $gateway = new SmsGateway([
        'slot' => SmsGateway::SLOT_PRIMARY,
        'endpoint' => 'https://sms.example.et/send',
        'method' => 'form',
        'auth_type' => 'none',
        'recipient_field' => 'to',
        'message_field' => 'message',
        'number_format' => 'international',
        'timeout_seconds' => 5,
    ]);
    $gateway->verified_at = now();
    $gateway->save();

    SmsSetting::current()->update([
        'enabled' => true,
        'daily_cap' => $cap,
        'invitations_enabled' => true,
        'invitation_share' => $share,
    ]);
}

function importRows(array $rows): array
{
    return test()->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'inv-'.uniqid(),
        'rows' => $rows,
        'create_logins' => true,
    ])->assertCreated()->json();
}

describe('the import', function () {
    it('sends nothing during the request and queues one invitation per login', function () {
        Notification::fake();
        Queue::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $result = importRows([
            ['name' => 'Abebe', 'email' => 'abebe@acme.test', 'hire_date' => '2024-01-15'],
            ['name' => 'Almaz', 'email' => 'almaz@acme.test', 'hire_date' => '2024-01-15'],
            ['name' => 'Bekele', 'email' => 'bekele@acme.test', 'hire_date' => '2024-01-15'],
        ]);

        Notification::assertNothingSent();
        Queue::assertPushed(SendInvitationJob::class, 3);

        expect($result['users_created'])->toBe(3)
            ->and($result['invitations_queued'])->toBe(3)
            ->and($result['in_person'])->toBe(0)
            ->and($result['invitations_batch'])->toBeString()
            ->and(InvitationSend::query()->where('status', InvitationSend::QUEUED)->count())->toBe(3);
    });

    it('delivers the queued invitations and reports them sent', function () {
        Notification::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        // The sync queue runs each job as it is dispatched.
        config(['queue.default' => 'sync']);

        $result = importRows([
            ['name' => 'Abebe', 'email' => 'abebe@acme.test', 'hire_date' => '2024-01-15'],
            ['name' => 'Almaz', 'email' => 'almaz@acme.test', 'hire_date' => '2024-01-15'],
        ]);

        Notification::assertSentTo(User::query()->where('email', 'abebe@acme.test')->firstOrFail(), AccountActivationNotification::class);
        Notification::assertSentTo(User::query()->where('email', 'almaz@acme.test')->firstOrFail(), AccountActivationNotification::class);

        app(CurrentTenant::class)->set($tenant);
        $this->getJson('/api/v1/users/invitations/'.$result['invitations_batch'])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('sent', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonMissingPath('id');
    });

    it('lists people with no email for an in-person code while texts are off', function () {
        Queue::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $result = importRows([
            ['name' => 'Phone Only', 'phone' => '0911223344', 'hire_date' => '2024-01-15'],
            ['name' => 'No Contact', 'hire_date' => '2024-01-15'],
        ]);

        expect($result['in_person'])->toBe(2)
            ->and($result['invitations_queued'])->toBe(0)
            ->and($result['invitations_batch'])->toBeNull();
        Queue::assertNothingPushed();
    });

    it('invites a phone-only person by text once text invitations are on', function () {
        Queue::fake();
        textInvitationsOn();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $result = importRows([['name' => 'Phone Only', 'phone' => '0911223344', 'hire_date' => '2024-01-15']]);

        expect($result['invitations_queued'])->toBe(1)
            ->and($result['in_person'])->toBe(0)
            ->and(InvitationSend::query()->firstOrFail()->channel)->toBe(InvitationSend::SMS);
    });
});

describe('the job', function () {
    it('skips someone who activated while it waited, and sends nothing', function () {
        Notification::fake();
        Queue::fake();
        $tenant = createTenant();
        $user = invitee($tenant, ['email' => 'late@acme.test']);
        app(InvitationDispatcher::class)->queue([$user]);
        $send = InvitationSend::query()->firstOrFail();
        $user->update(['status' => 'active']);

        runInvitation($send);

        expect($send->fresh()->status)->toBe(InvitationSend::SKIPPED)
            ->and($send->fresh()->error_code)->toBe('not_invited');
        Notification::assertNothingSent();
    });

    it('does nothing with another organisation\'s row', function () {
        Notification::fake();
        $owner = createTenant();
        $user = invitee($owner, ['email' => 'mine@acme.test']);
        $send = InvitationSend::query()->create([
            'tenant_id' => $owner->id, 'user_id' => $user->id, 'channel' => InvitationSend::EMAIL,
            'status' => InvitationSend::QUEUED, 'queued_at' => now(),
        ]);
        $other = Tenant::factory()->create();

        app(CurrentTenant::class)->forget();
        (new SendInvitationJob($other->id, $send->id))->withFakeQueueInteractions()->handle(
            app(CurrentTenant::class),
            app(UserProvisioningService::class),
            app(ConfiguredSmsSender::class),
            app(InvitationDispatcher::class),
        );

        expect($send->fresh()->status)->toBe(InvitationSend::QUEUED);
        Notification::assertNothingSent();
    });

    it('waits for the organisation\'s per-minute limit instead of failing', function () {
        Notification::fake();
        Queue::fake();
        config(['invitations.per_minute' => 1]);
        $tenant = createTenant();
        app(InvitationDispatcher::class)->queue([
            invitee($tenant, ['email' => 'one@acme.test']),
            invitee($tenant, ['email' => 'two@acme.test']),
        ]);
        [$first, $second] = InvitationSend::query()->orderBy('id')->get()->all();
        RateLimiter::clear('invitations:email:'.$tenant->id);

        runInvitation($first)->assertNotReleased();
        runInvitation($second)->assertReleased();

        expect($first->fresh()->status)->toBe(InvitationSend::SENT)
            ->and($second->fresh()->status)->toBe(InvitationSend::QUEUED);
    });

    it('marks the row failed once retries are exhausted', function () {
        Queue::fake();
        $tenant = createTenant();
        app(InvitationDispatcher::class)->queue([invitee($tenant, ['email' => 'x@acme.test'])]);
        $send = InvitationSend::query()->firstOrFail();

        app(CurrentTenant::class)->forget();
        (new SendInvitationJob($tenant->id, $send->id))->failed(new RuntimeException('smtp down'));

        expect($send->fresh()->status)->toBe(InvitationSend::FAILED)
            ->and($send->fresh()->error_code)->toBe('delivery');
    });

    it('sends a text with no link token, counted as an invitation', function () {
        Queue::fake();
        Http::fake(['*' => Http::response('OK')]);
        textInvitationsOn();
        $tenant = createTenant();
        $user = invitee($tenant, ['email' => null, 'phone' => '+251911223344']);
        app(InvitationDispatcher::class)->queue([$user]);

        runInvitation(InvitationSend::query()->firstOrFail());

        expect(InvitationSend::query()->firstOrFail()->status)->toBe(InvitationSend::SENT)
            ->and(SmsSend::countToday(SmsSend::PURPOSE_INVITATION))->toBe(1);
        Http::assertSent(fn ($request) => str_contains((string) $request['message'], $tenant->name)
            && ! str_contains((string) $request['message'], 'token'));
    });

    it('waits for tomorrow when the invitations\' share of the cap is used up', function () {
        Http::fake(['*' => Http::response('OK')]);
        textInvitationsOn(share: 0);
        $tenant = createTenant();
        app(InvitationDispatcher::class)->queue([invitee($tenant, ['email' => null, 'phone' => '+251911223344'])]);

        runInvitation(InvitationSend::query()->firstOrFail())->assertReleased();

        Http::assertNothingSent();
    });
});

describe('sending to many people', function () {
    it('sends once when asked twice in a row', function () {
        Queue::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $user = invitee($tenant, ['email' => 'twice@acme.test']);

        $this->postJson('/api/v1/users/invitations', ['user_ids' => [$user->public_id]])
            ->assertStatus(202)->assertJsonPath('queued', 1);
        $this->postJson('/api/v1/users/invitations', ['user_ids' => [$user->public_id]])
            ->assertStatus(202)->assertJsonPath('queued', 0)->assertJsonPath('skipped', 1);

        Queue::assertPushed(SendInvitationJob::class, 1);
    });

    it('invites everyone not activated, and no one else', function () {
        Queue::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        invitee($tenant, ['email' => 'a@acme.test']);
        invitee($tenant, ['email' => 'b@acme.test']);
        createUser(['status' => 'active', 'email' => 'c@acme.test'], $tenant);
        invitee(Tenant::factory()->create(), ['email' => 'elsewhere@other.test']);
        app(CurrentTenant::class)->set($tenant);

        $this->postJson('/api/v1/users/invitations', ['scope' => 'not_activated'])
            ->assertStatus(202)->assertJsonPath('queued', 2);
    });

    it('does not show another organisation\'s batch', function () {
        Queue::fake();
        $other = createTenant();
        $batch = app(InvitationDispatcher::class)->queue([invitee($other, ['email' => 'o@other.test'])])['batch'];

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson("/api/v1/users/invitations/{$batch}")->assertNotFound();
    });

    it('is refused to an employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->postJson('/api/v1/users/invitations', ['scope' => 'not_activated'])->assertForbidden();
    });

    it('shows each person\'s last invitation on the users list', function () {
        Queue::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        app(InvitationDispatcher::class)->queue([invitee($tenant, ['email' => 'shown@acme.test'])]);

        $row = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))
            ->firstWhere('email', 'shown@acme.test');

        expect($row['last_invitation']['channel'])->toBe('email')
            ->and($row['last_invitation']['status'])->toBe('queued');
    });
});

describe('reminders', function () {
    it('reminds someone three days after their last invitation', function () {
        Queue::fake();
        $tenant = createTenant();
        $user = invitee($tenant, ['email' => 'slow@acme.test', 'invited_at' => now()->subDays(4)]);
        InvitationSend::query()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'channel' => InvitationSend::EMAIL,
            'status' => InvitationSend::SENT, 'queued_at' => now()->subDays(4), 'sent_at' => now()->subDays(4),
        ]);

        $this->artisan('invitations:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendInvitationJob::class, 1);
        app(CurrentTenant::class)->set($tenant);
        expect(InvitationSend::query()->where('reminder', true)->count())->toBe(1);
    });

    it('stops after two reminders, and leaves recent invitations alone', function () {
        Queue::fake();
        $tenant = createTenant();
        $twice = invitee($tenant, ['email' => 'twice@acme.test', 'invited_at' => now()->subDays(10)]);
        foreach ([9, 6] as $days) {
            InvitationSend::query()->create([
                'tenant_id' => $tenant->id, 'user_id' => $twice->id, 'channel' => InvitationSend::EMAIL, 'reminder' => true,
                'status' => InvitationSend::SENT, 'queued_at' => now()->subDays($days),
            ]);
        }
        invitee($tenant, ['email' => 'new@acme.test', 'invited_at' => now()->subDay()]);

        $this->artisan('invitations:send-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('does not chase an account that was never sent an invitation', function () {
        Queue::fake();
        $tenant = createTenant();
        invitee($tenant, ['email' => 'quiet@acme.test', 'invited_at' => now()->subDays(4)]);

        $this->artisan('invitations:send-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('sends none for an organisation that turned them off', function () {
        Queue::fake();
        $tenant = createTenant(['settings' => ['invitation_reminders' => false]]);
        $user = invitee($tenant, ['email' => 'off@acme.test', 'invited_at' => now()->subDays(4)]);
        InvitationSend::query()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'channel' => InvitationSend::EMAIL,
            'status' => InvitationSend::SENT, 'queued_at' => now()->subDays(4), 'sent_at' => now()->subDays(4),
        ]);

        $this->artisan('invitations:send-reminders')->assertSuccessful();

        Queue::assertNothingPushed();
    });
});

describe('a person with only a phone', function () {
    it('can be given an in-person code while texts are off', function () {
        $tenant = createTenant(['settings' => ['login_identifiers' => ['employee_code', 'email']]]);
        $actor = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);

        $issued = app(ActivationCodeService::class)->issue($employee, $actor, 'national_id', null);

        expect($issued['code'])->toBeString();
    });

    it('is refused an in-person code once texts are on', function () {
        textInvitationsOn();
        $tenant = createTenant(['settings' => ['login_identifiers' => ['employee_code', 'email']]]);
        $actor = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);

        expect(fn () => app(ActivationCodeService::class)->issue($employee, $actor, 'national_id', null))
            ->toThrow(ActivationCodeRefused::class);
    });

    it('is activated by signing in with a text code once texts are on', function () {
        textInvitationsOn();
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);
        $user = app(UserProvisioningService::class)->provisionWithPhone($employee);
        $code = otpCodeFor($user);

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
            'phone' => '0911223344',
            'code' => $code,
        ])->assertOk();

        expect($user->fresh()->status)->toBe('active');
    });

    it('stays invited when signing in with a text code while texts are off', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);
        $user = app(UserProvisioningService::class)->provisionWithPhone($employee);
        $code = otpCodeFor($user);

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/otp/verify", [
            'phone' => '0911223344',
            'code' => $code,
        ])->assertForbidden();

        expect($user->fresh()->status)->toBe('invited');
    });
});

/** Issue a sign-in code through a recording sender and read it back from the message. */
function otpCodeFor(User $user): string
{
    $sender = new class implements SmsSender
    {
        public string $message = '';

        public function send(string $to, string $message): bool
        {
            $this->message = $message;

            return true;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };
    app()->instance(SmsSender::class, $sender);
    app()->forgetInstance(OtpService::class);

    app(OtpService::class)->issue($user, OtpCode::PURPOSE_LOGIN, OtpService::CHANNEL_SMS);
    preg_match('/\b(\d{6})\b/', $sender->message, $m);

    return $m[1];
}
