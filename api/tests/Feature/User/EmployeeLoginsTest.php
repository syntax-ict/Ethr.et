<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\SendInvitationJob;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * After a device migration (EMPLOYEE-INVITATIONS-PLAN, PR C): the migration
 * creates employees and no logins, says who cannot sign in yet, and one
 * request gives them all a way in, with the invitations sent from the queue.
 */
function loginsTenant(): Tenant
{
    $tenant = createTenant(['settings' => ['login_identifiers' => ['employee_code', 'email']]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

function loginsApi(Tenant $tenant, string $method, string $path, array $body = []): TestResponse
{
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";

    return $method === 'GET' ? test()->getJson($url) : test()->postJson($url, $body);
}

function textsOn(): void
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

    SmsSetting::current()->update(['enabled' => true, 'invitations_enabled' => true]);
}

/** @return array{0: string, 1: TestResponse} the batch public id and the commit response */
function migrated(Tenant $tenant, array $rows): array
{
    $batch = loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['rows' => $rows])->assertCreated()->json('public_id');

    return [$batch, loginsApi($tenant, 'POST', "onboarding/migration/batches/{$batch}/commit")->assertOk()];
}

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

it('says after a migration who cannot sign in, how each would be invited, and that hire dates were defaulted', function () {
    $tenant = loginsTenant();

    [$batch, $commit] = migrated($tenant, [
        ['name' => 'Abebe Kebede', 'email' => 'abebe@acme.et'],
        ['name' => 'Hanna Girma', 'phone' => '0911223344'],
        ['name' => 'Guest card'],
        ['name' => 'Dawit Bekele', 'email' => 'dawit@acme.et', 'hire_date' => '2019-03-01'],
    ]);

    $commit->assertJsonPath('hire_dates_defaulted', 3)
        ->assertJsonCount(4, 'without_login')
        ->assertJsonPath('without_login.0.name', 'Abebe Kebede')
        ->assertJsonPath('without_login.0.channel', 'email')
        ->assertJsonPath('without_login.0.invited', false)
        ->assertJsonPath('without_login.1.channel', 'in_person')
        ->assertJsonPath('without_login.2.channel', 'in_person');

    // Nothing was created or sent by the commit itself.
    expect(User::query()->where('role', UserRole::EMPLOYEE)->count())->toBe(0);
    Queue::assertNothingPushed();

    loginsApi($tenant, 'GET', "onboarding/migration/batches/{$batch}/logins")->assertOk()
        ->assertJsonCount(4, 'without_login')
        ->assertJsonPath('hire_dates_defaulted', 3);
});

it('gives each employee a way in with one request and queues the invitations, sending nothing inline', function () {
    $tenant = loginsTenant();
    $email = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'abebe@acme.et', 'phone' => null]);
    $phone = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);
    $none = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null]);
    $active = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'active@acme.et']);
    createUser(['employee_id' => $active->id, 'email' => 'active@acme.et', 'status' => 'active'], $tenant);

    loginsApi($tenant, 'POST', 'employees/logins', [
        'employee_ids' => [$email->public_id, $phone->public_id, $none->public_id, $active->public_id],
    ])->assertStatus(202)
        ->assertJsonPath('created', 1)
        ->assertJsonPath('active', 1)
        ->assertJsonPath('in_person', 2)
        ->assertJsonPath('email_in_use', 0)
        ->assertJsonPath('queued', 1)
        ->assertJsonPath('batch', fn ($batch) => is_string($batch));

    expect($email->fresh()->user?->status)->toBe('invited')
        ->and($phone->fresh()->user)->toBeNull()
        ->and($none->fresh()->user)->toBeNull();
    Queue::assertPushed(SendInvitationJob::class, 1);
    Notification::assertNothingSent();
});

it('invites someone with only a phone by text once text invitations are on', function () {
    $tenant = loginsTenant();
    textsOn();
    $phone = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+251911223344']);

    loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$phone->public_id]])
        ->assertStatus(202)->assertJsonPath('created', 1)->assertJsonPath('queued', 1);

    expect($phone->fresh()->user?->phone)->toBe('+251911223344')
        ->and(InvitationSend::query()->value('channel'))->toBe(InvitationSend::SMS);
});

it('invites again someone already invited and not yet activated, without a second login', function () {
    $tenant = loginsTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'abebe@acme.et']);
    createUser(['employee_id' => $employee->id, 'email' => 'abebe@acme.et', 'status' => 'invited', 'role' => UserRole::EMPLOYEE], $tenant);

    loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$employee->public_id]])
        ->assertStatus(202)->assertJsonPath('created', 0)->assertJsonPath('queued', 1);

    expect(User::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('counts an email another login already uses, and creates nothing for it', function () {
    $tenant = loginsTenant();
    createUser(['email' => 'shared@acme.et'], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'shared@acme.et']);

    loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$employee->public_id]])
        ->assertStatus(202)->assertJsonPath('email_in_use', 1)->assertJsonPath('created', 0);

    Queue::assertNothingPushed();
});

it('answers another organisation\'s employee exactly as an id that matches nobody', function () {
    $tenant = loginsTenant();
    $mine = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'mine@acme.et']);
    $other = Tenant::factory()->create();
    $theirs = Employee::withoutEvents(fn () => Employee::factory()->create(['tenant_id' => $other->id, 'email' => 'theirs@other.et']));

    $foreign = loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$mine->public_id, $theirs->public_id]])
        ->assertStatus(422)->json('errors.employee_ids');
    $missing = loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$mine->public_id, str_repeat('Z', 26)]])
        ->assertStatus(422)->json('errors.employee_ids');

    expect($foreign)->toBe($missing)
        ->and(User::withoutGlobalScopes()->where('email', 'theirs@other.et')->exists())->toBeFalse()
        ->and($mine->fresh()->user)->toBeNull();
});

it('needs permission to invite users', function () {
    $tenant = loginsTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'abebe@acme.et']);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$employee->public_id]])->assertForbidden();
});
