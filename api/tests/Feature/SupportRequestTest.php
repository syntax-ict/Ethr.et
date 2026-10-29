<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SupportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SupportRequestNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Get help, end to end: an employee asks from inside ETHR, the organisation's
 * HR answers or passes it to ETHR, ETHR answers from the console, and every
 * step reaches whoever moves next. Most tests are about who may see what:
 * another employee, another organisation, the person who asked.
 */
function supportUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/support-requests{$path}";
}

const SUPPORT_CONSOLE = 'http://admin.ethr.et/api/v1/admin/support-requests';

function supportPerson(Tenant $tenant, UserRole $role = UserRole::EMPLOYEE): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return createUser(['role' => $role, 'employee_id' => $employee->id], $tenant);
}

function ethrAdmin(): User
{
    // Forgotten first: a resolved tenant would be stamped on the new account
    // by BelongsToTenant, and a super admin belongs to none.
    app(CurrentTenant::class)->forget();

    return User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
    ]);
}

/** Acts on the console host, where no tenant is resolved. */
function asConsole(User $admin): void
{
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();
    test()->actingAs($admin);
}

function asTenantUser(User $user): void
{
    config(['app.domain' => env('APP_DOMAIN')]);
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();
    test()->actingAs($user);
}

/** @param  array<string, mixed>  $extra */
function askForHelp(Tenant $tenant, User $user, array $extra = []): string
{
    asTenantUser($user);

    return test()->postJson(supportUrl($tenant), [
        'category' => 'attendance',
        'body' => 'My punch this morning did not count.',
        'context' => [
            'page' => '/attendance?search=abebe@example.com',
            'locale' => 'am',
            'viewport' => '390x844',
            'online' => true,
            'pending_offline' => 2,
            'recent_errors' => [
                ['method' => 'POST', 'path' => '/attendance/check-in?x=1', 'status' => 422, 'type' => 'https://ethr.et/errors/validation', 'at' => '2026-10-29T06:01:00Z'],
            ],
        ],
        ...$extra,
    ], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130'])->assertCreated()->json('public_id');
}

function sentEvent(object $notification, object $notifiable): string
{
    return $notification->toArray($notifiable)['type'];
}

describe('asking', function () {
    it('stores the question with what the screen knew, and tells HR', function () {
        Notification::fake();
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $colleague = supportPerson($tenant);

        $id = askForHelp($tenant, $employee);

        $request = SupportRequest::withoutGlobalScopes()->where('public_id', $id)->sole();
        expect($request->status)->toBe('open')
            ->and($request->handled_by)->toBe('organisation')
            ->and($request->escalated_at)->toBeNull()
            // Query strings carry search terms: never kept.
            ->and($request->context['page'])->toBe('/attendance')
            ->and($request->context['recent_errors'][0]['path'])->toBe('/attendance/check-in')
            ->and($request->context['pending_offline'])->toBe(2)
            ->and($request->context['role'])->toBe('employee')
            ->and($request->context['user_agent'])->toContain('Android 14');

        Notification::assertSentTo($hr, SupportRequestNotification::class, fn ($n) => sentEvent($n, $hr) === 'support_received');
        Notification::assertNotSentTo($employee, SupportRequestNotification::class);
        Notification::assertNotSentTo($colleague, SupportRequestNotification::class);
        expect(AuditLog::withoutGlobalScopes()->where('action', 'support.request_created')->count())->toBe(1);
    });

    it('keeps only what the server knows when technical details are left out', function () {
        $tenant = createTenant();
        $id = askForHelp($tenant, supportPerson($tenant), ['include_context' => false]);

        $context = SupportRequest::withoutGlobalScopes()->where('public_id', $id)->sole()->context;
        expect($context)->toHaveKeys(['role', 'release', 'included'])
            ->and($context['included'])->toBeFalse()
            ->and($context)->not->toHaveKey('page')
            ->and($context)->not->toHaveKey('user_agent');
    });

    it('answers a missing message with a validation error', function () {
        $tenant = createTenant();
        asTenantUser(supportPerson($tenant));

        test()->postJson(supportUrl($tenant), ['category' => 'nonsense', 'body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'body']);
    });

    it('limits how many requests one person sends in an hour', function () {
        Notification::fake();
        $tenant = createTenant();
        $employee = supportPerson($tenant);

        foreach (range(1, 10) as $i) {
            askForHelp($tenant, $employee);
        }

        test()->postJson(supportUrl($tenant), ['category' => 'other', 'body' => 'One more'])
            ->assertTooManyRequests();
    });
});

describe('who sees a request', function () {
    it('shows a request to the person who asked and to HR, and to no other colleague', function () {
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $colleague = supportPerson($tenant);
        $id = askForHelp($tenant, $employee);

        asTenantUser($employee);
        test()->getJson(supportUrl($tenant, "/{$id}"))->assertOk()
            ->assertJsonPath('body', 'My punch this morning did not count.')
            ->assertJsonPath('viewer', 'requester')
            ->assertJsonPath('requester.email', null)
            ->assertJsonMissingPath('id');
        expect(test()->getJson(supportUrl($tenant, '/mine'))->json('data'))->toHaveCount(1);

        asTenantUser($colleague);
        test()->getJson(supportUrl($tenant, "/{$id}"))->assertNotFound();
        test()->getJson(supportUrl($tenant))->assertForbidden();
        expect(test()->getJson(supportUrl($tenant, '/mine'))->json('data'))->toBe([]);

        asTenantUser($hr);
        test()->getJson(supportUrl($tenant, "/{$id}"))->assertOk()
            ->assertJsonPath('viewer', 'organisation')
            ->assertJsonPath('requester.email', $employee->email);
        test()->getJson(supportUrl($tenant))->assertOk()
            ->assertJsonPath('data.0.public_id', $id)
            ->assertJsonPath('counts.open', 1);
    });

    it('never shows one organisation\'s request to another', function () {
        $tenant = createTenant();
        $other = createTenant();
        $id = askForHelp($tenant, supportPerson($tenant));

        asTenantUser(supportPerson($other, UserRole::HR_ADMIN));
        test()->getJson(supportUrl($other, "/{$id}"))->assertNotFound();
        expect(test()->getJson(supportUrl($other))->json('data'))->toBe([]);
    });
});

describe('the conversation', function () {
    it('moves between HR and the person who asked, telling each in turn', function () {
        Notification::fake();
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $id = askForHelp($tenant, $employee);

        asTenantUser($hr);
        test()->postJson(supportUrl($tenant, "/{$id}/messages"), ['body' => 'Which branch were you at?'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered')
            ->assertJsonPath('messages.0.author', 'organisation')
            ->assertJsonPath('messages.0.author_name', $hr->name);
        Notification::assertSentTo($employee, SupportRequestNotification::class, fn ($n) => sentEvent($n, $employee) === 'support_answered');

        asTenantUser($employee);
        test()->postJson(supportUrl($tenant, "/{$id}/messages"), ['body' => 'Bole branch'])
            ->assertCreated()
            ->assertJsonPath('status', 'open');
        Notification::assertSentTo($hr, SupportRequestNotification::class, fn ($n) => sentEvent($n, $hr) === 'support_requester_replied');

        test()->postJson(supportUrl($tenant, "/{$id}/resolve"))->assertOk()->assertJsonPath('status', 'resolved');

        // A reply reopens it: nothing is closed against the person who asked.
        test()->postJson(supportUrl($tenant, "/{$id}/messages"), ['body' => 'It happened again'])
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('resolved_at', null);
    });

    it('refuses an empty reply', function () {
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $id = askForHelp($tenant, $employee);

        asTenantUser($employee);
        test()->postJson(supportUrl($tenant, "/{$id}/messages"), ['body' => '<b></b>'])
            ->assertStatus(422);
    });
});

describe('passing a request to ETHR', function () {
    it('lets HR pass it on, once, and tells ETHR and the person who asked', function () {
        Notification::fake();
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $platform = ethrAdmin();
        $id = askForHelp($tenant, $employee);

        asTenantUser($employee);
        test()->postJson(supportUrl($tenant, "/{$id}/escalate"))->assertForbidden();

        asTenantUser($hr);
        test()->postJson(supportUrl($tenant, "/{$id}/escalate"), ['note' => 'Device shows the punch; app does not.'])
            ->assertOk()
            ->assertJsonPath('handled_by', 'ethr')
            ->assertJsonPath('escalated_by_name', $hr->name);
        test()->postJson(supportUrl($tenant, "/{$id}/escalate"))->assertStatus(409);

        Notification::assertSentTo($platform, SupportRequestNotification::class, fn ($n) => sentEvent($n, $platform) === 'support_escalated');
        Notification::assertSentTo($employee, SupportRequestNotification::class, fn ($n) => sentEvent($n, $employee) === 'support_passed_to_ethr');

        // The note is between the organisation and ETHR.
        asTenantUser($employee);
        test()->getJson(supportUrl($tenant, "/{$id}"))
            ->assertJsonPath('handled_by', 'ethr')
            ->assertJsonPath('escalation_note', null)
            ->assertJsonPath('escalated_by_name', null);
    });

    it('lets a support person send their own question straight to ETHR', function () {
        Notification::fake();
        $tenant = createTenant();
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $platform = ethrAdmin();

        asTenantUser(supportPerson($tenant));
        test()->postJson(supportUrl($tenant), ['category' => 'billing', 'body' => 'Our invoice is wrong', 'send_to' => 'ethr'])
            ->assertForbidden();

        $id = askForHelp($tenant, $hr, ['send_to' => 'ethr']);

        $request = SupportRequest::withoutGlobalScopes()->where('public_id', $id)->sole();
        expect($request->handled_by)->toBe('ethr')
            ->and($request->escalated_by_user_id)->toBe($hr->id);
        Notification::assertSentTo($platform, SupportRequestNotification::class, fn ($n) => sentEvent($n, $platform) === 'support_escalated');
    });
});

describe('the console', function () {
    it('lists only requests passed to ETHR, from every organisation, with the organisation named', function () {
        $acme = createTenant(['name' => 'Acme Coffee']);
        $habesha = createTenant(['name' => 'Habesha Bank']);
        $kept = askForHelp($acme, supportPerson($acme));
        $passed = askForHelp($acme, $acmeHr = supportPerson($acme, UserRole::HR_ADMIN), ['send_to' => 'ethr']);
        $other = askForHelp($habesha, supportPerson($habesha, UserRole::HR_ADMIN), ['send_to' => 'ethr']);

        asConsole(ethrAdmin());
        $list = test()->getJson(SUPPORT_CONSOLE)->assertOk();

        expect(collect($list->json('data'))->pluck('public_id')->sort()->values()->all())
            ->toBe(collect([$passed, $other])->sort()->values()->all())
            ->and(collect($list->json('data'))->pluck('organisation.name')->sort()->values()->all())
            ->toBe(['Acme Coffee', 'Habesha Bank'])
            ->and($list->json('counts.open'))->toBe(2);

        test()->getJson(SUPPORT_CONSOLE."/{$kept}")->assertNotFound();
        // Names are read through the employee, which the console host cannot
        // see without stating the organisation: not the login email.
        $asker = Employee::withoutGlobalScopes()->findOrFail($acmeHr->employee_id);
        expect(collect($list->json('data'))->firstWhere('public_id', $passed)['requester']['name'])->toBe($asker->name);

        test()->getJson(SUPPORT_CONSOLE."/{$passed}")->assertOk()
            ->assertJsonPath('viewer', 'ethr')
            ->assertJsonPath('requester.name', $asker->name)
            ->assertJsonPath('organisation.name', 'Acme Coffee')
            ->assertJsonPath('context.page', '/attendance')
            ->assertJsonMissingPath('id');
    });

    it('answers from ETHR and reaches the person who asked and the colleague who passed it on', function () {
        Notification::fake();
        $tenant = createTenant();
        $employee = supportPerson($tenant);
        $hr = supportPerson($tenant, UserRole::HR_ADMIN);
        $platform = ethrAdmin();
        $id = askForHelp($tenant, $employee);
        asTenantUser($hr);
        test()->postJson(supportUrl($tenant, "/{$id}/escalate"))->assertOk();

        asConsole($platform);
        test()->postJson(SUPPORT_CONSOLE."/{$id}/messages", ['body' => 'Fixed: the punch is now on your record.'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered')
            ->assertJsonPath('messages.0.author', 'ethr')
            ->assertJsonPath('messages.0.author_name', $platform->name);

        Notification::assertSentTo($employee, SupportRequestNotification::class, fn ($n) => sentEvent($n, $employee) === 'support_answered');
        Notification::assertSentTo($hr, SupportRequestNotification::class, fn ($n) => sentEvent($n, $hr) === 'support_answered');

        // The person who asked reads it as "ETHR support", not a staff name.
        asTenantUser($employee);
        test()->getJson(supportUrl($tenant, "/{$id}"))
            ->assertJsonPath('messages.0.author', 'ethr')
            ->assertJsonPath('messages.0.author_name', null);

        // Their reply goes back to ETHR, not to HR.
        test()->postJson(supportUrl($tenant, "/{$id}/messages"), ['body' => 'Thank you'])->assertCreated();
        Notification::assertSentTo($platform, SupportRequestNotification::class, fn ($n) => sentEvent($n, $platform) === 'support_requester_replied');

        asConsole($platform);
        test()->postJson(SUPPORT_CONSOLE."/{$id}/resolve")->assertOk()->assertJsonPath('status', 'resolved');
        Notification::assertSentTo($employee, SupportRequestNotification::class, fn ($n) => sentEvent($n, $employee) === 'support_resolved');
    });

    it('links ETHR to the console host', function () {
        $tenant = createTenant();
        $id = askForHelp($tenant, supportPerson($tenant, UserRole::HR_ADMIN), ['send_to' => 'ethr']);
        config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://www.ethr.et']);
        $request = SupportRequest::withoutGlobalScopes()->where('public_id', $id)->sole();
        $platform = ethrAdmin();

        $mail = (new SupportRequestNotification($request, 'escalated', 'ethr', 'x'))->toMail($platform);

        expect($mail->actionUrl)->toBe("https://admin.ethr.et/admin/support?request={$id}");
    });

    it('is closed to anyone but the platform', function () {
        $tenant = createTenant();
        $id = askForHelp($tenant, $hr = supportPerson($tenant, UserRole::TENANT_ADMIN), ['send_to' => 'ethr']);

        asConsole($hr);
        test()->getJson(SUPPORT_CONSOLE)->assertForbidden();
        test()->postJson(SUPPORT_CONSOLE."/{$id}/messages", ['body' => 'x'])->assertForbidden();
    });
});
