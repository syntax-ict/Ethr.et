<?php

declare(strict_types=1);

use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Jobs\SendInvitationJob;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\MigrationStagingRow;
use App\Models\SmsGateway;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invitations\EmployeeLogins;
use App\Services\Migration\WorkforceMigrationService;
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

it('stops at the requester\'s reach: people in it get logins, one beyond it refuses everything', function () {
    Queue::fake();
    $tenant = createTenant(['settings' => ['login_identifiers' => ['employee_code', 'email']]]);
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id, 'email' => 'head@acme.et']);
    $near = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id, 'email' => 'near@acme.et']);
    $far = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id, 'email' => 'far@acme.et']);
    test()->actingAs(createScopedUser($tenant, $head->id, OrgScope::DEPARTMENT->value, ['users.invite', 'employee.update']));

    $refused = loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$near->public_id, $far->public_id]])
        ->assertStatus(422)->json('errors.employee_ids');
    $unknown = loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$near->public_id, str_repeat('Z', 26)]])
        ->assertStatus(422)->json('errors.employee_ids');

    expect($refused)->toBe($unknown)
        ->and($near->fresh()->user)->toBeNull()
        ->and($far->fresh()->user)->toBeNull();

    loginsApi($tenant, 'POST', 'employees/logins', ['employee_ids' => [$near->public_id]])
        ->assertStatus(202)
        ->assertJsonPath('created', 1);

    expect($near->fresh()->user)->not->toBeNull()
        ->and($far->fresh()->user)->toBeNull();
});

/*
 * The sign-in column of a staged list (owner decision 2026-11-16): a row says
 * yes or no for its own person and outranks the tick on the review step. It
 * lives on the staged row and is read back as `asked`; it is never written to
 * a person.
 */
it('says after a commit what the list asked for each person, and writes it to nobody', function () {
    $tenant = loginsTenant();

    [$batch, $commit] = migrated($tenant, [
        ['name' => 'Abebe Kebede', 'email' => 'abebe@acme.et', 'sign_in' => 'yes'],
        ['name' => 'Hanna Girma', 'email' => 'hanna@acme.et', 'sign_in' => 'no'],
        ['name' => 'Dawit Bekele', 'email' => 'dawit@acme.et'],
    ]);

    $commit->assertJsonPath('without_login.0.name', 'Abebe Kebede')
        ->assertJsonPath('without_login.0.asked', true)
        ->assertJsonPath('without_login.1.name', 'Hanna Girma')
        ->assertJsonPath('without_login.1.asked', false)
        ->assertJsonPath('without_login.2.name', 'Dawit Bekele')
        ->assertJsonPath('without_login.2.asked', null);

    loginsApi($tenant, 'GET', "onboarding/migration/batches/{$batch}/logins")->assertOk()
        ->assertJsonPath('without_login.0.asked', true)
        ->assertJsonPath('without_login.1.asked', false)
        ->assertJsonPath('without_login.2.asked', null);

    // On the staged row, as the list said it, and on nothing else.
    $kept = MigrationStagingRow::query()->orderBy('id')->get()->map(fn (MigrationStagingRow $row): mixed => $row->raw['sign_in'] ?? null)->all();
    expect($kept)->toBe(['yes', 'no', null]);

    foreach (Employee::query()->where('tenant_id', $tenant->id)->get() as $employee) {
        expect($employee->getAttributes())->not->toHaveKey('sign_in')
            ->and(json_encode($employee->toArray()))->not->toContain('sign_in');
    }
    expect(User::query()->where('role', UserRole::EMPLOYEE)->count())->toBe(0)
        ->and(AuditLog::query()->get()->contains(fn (AuditLog $log): bool => str_contains((string) json_encode($log->getAttributes()), 'sign_in')))->toBeFalse();
});

it('lets no win when two lines of one list are the same person', function (array $says) {
    $tenant = loginsTenant();
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'employee_code' => 'E-1',
        'email' => 'abebe@acme.et', 'hire_date' => '2020-01-01',
    ]);

    $batch = app(WorkforceMigrationService::class)->stageFromRows(
        array_map(fn (string $value): array => ['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'sign_in' => $value], $says),
        $tenant->id,
    );
    expect($batch->rows()->pluck('resolved_employee_id')->unique()->count())->toBe(1);

    loginsApi($tenant, 'POST', "onboarding/migration/batches/{$batch->public_id}/commit")
        ->assertOk()
        ->assertJsonCount(1, 'without_login')
        ->assertJsonPath('without_login.0.asked', false);
})->with([
    'yes then no' => [['yes', 'no']],
    'no then yes' => [['no', 'yes']],
]);

it('says before anyone is added what the list will do, by who an invitation can reach and what the list says', function () {
    $tenant = loginsTenant();

    $staged = loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['rows' => [
        ['name' => 'Abebe Kebede', 'email' => 'abebe@acme.et', 'sign_in' => 'yes'],
        ['name' => 'Hanna Girma', 'email' => 'hanna@acme.et', 'sign_in' => 'no'],
        ['name' => 'Dawit Bekele', 'email' => 'dawit@acme.et'],
        ['name' => 'Sara Tesfaye', 'phone' => '0911223344', 'sign_in' => 'yes'],
        ['name' => 'Yonas Haile', 'sign_in' => 'no'],
        ['name' => 'Guest card'],
    ]])->assertCreated()->json();
    $url = "onboarding/migration/batches/{$staged['public_id']}";

    // Texts are off: a phone alone cannot be invited.
    loginsApi($tenant, 'GET', $url)->assertOk()->assertJsonPath('sign_in_plan', [
        'people' => 6,
        'invitable' => ['yes' => 1, 'no' => 1, 'blank' => 1],
        'in_person' => ['yes' => 1, 'no' => 1, 'blank' => 1],
        'in_person_possible' => true,
    ]);

    // The same rule EmployeeLogins would use: with texts on, the phone is reached.
    textsOn();
    loginsApi($tenant, 'GET', $url)->assertOk()
        ->assertJsonPath('sign_in_plan.invitable', ['yes' => 2, 'no' => 1, 'blank' => 1])
        ->assertJsonPath('sign_in_plan.in_person', ['yes' => 0, 'no' => 1, 'blank' => 1]);

    // A code handed over in person works only where people sign in by employee number.
    $tenant->update(['settings' => ['login_identifiers' => ['email']]]);
    loginsApi($tenant, 'GET', $url)->assertOk()->assertJsonPath('sign_in_plan.in_person_possible', false);
});

it('counts only the people a list creates, and a person set aside is not counted', function () {
    $tenant = loginsTenant();

    $staged = loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['rows' => [
        ['name' => 'Abebe Kebede', 'email' => 'abebe@acme.et', 'sign_in' => 'yes'],
        ['name' => 'Hanna Girma', 'email' => 'hanna@acme.et', 'sign_in' => 'yes'],
    ]])->assertCreated()->json();

    $row = $staged['rows'][1]['public_id'];
    $this->patchJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/migration/rows/{$row}", ['action' => 'skip'])->assertOk();

    loginsApi($tenant, 'GET', "onboarding/migration/batches/{$staged['public_id']}")->assertOk()
        ->assertJsonPath('sign_in_plan.people', 1)
        ->assertJsonPath('sign_in_plan.invitable.yes', 1);
});

it('drops the sign-in column from a list that completes people already here, and plans nothing', function () {
    $tenant = loginsTenant();
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'employee_code' => 'E-1',
        'email' => null, 'phone' => null, 'hire_date' => '2020-01-01',
    ]);

    $staged = loginsApi($tenant, 'POST', 'onboarding/migration/rows', [
        'mode' => 'complete',
        'grid' => [['Name', 'Employee number', 'Sign in'], ['Abebe Kebede', 'E-1', 'yes']],
    ])->assertCreated()->json();

    expect(MigrationStagingRow::query()->firstOrFail()->raw)->not->toHaveKey('sign_in');
    loginsApi($tenant, 'GET', "onboarding/migration/batches/{$staged['public_id']}")->assertOk()
        ->assertJsonPath('mode', 'complete')
        ->assertJsonPath('sign_in_plan', null);
});

it('answers 422, not 500, to a complete-mode list whose rows are not rows', function () {
    $tenant = loginsTenant();

    loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['mode' => 'complete', 'rows' => ['x']])->assertUnprocessable();
});

it('uses one rule to say how someone would be invited', function () {
    $tenant = loginsTenant();
    $logins = app(EmployeeLogins::class);

    expect($logins->channelFor('abebe@acme.et', '0911223344'))->toBe(InvitationSend::EMAIL)
        ->and($logins->channelFor(null, '0911223344'))->toBe(InvitationSend::IN_PERSON)
        ->and($logins->channelFor('  ', null))->toBe(InvitationSend::IN_PERSON);

    textsOn();
    expect($logins->channelFor(null, '0911223344'))->toBe(InvitationSend::SMS);
});

it('ignores a sign-in value in a list that completes people, even one that would be refused in a list that adds them', function () {
    $tenant = loginsTenant();
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'employee_code' => 'E-1',
        'email' => null, 'phone' => null, 'hire_date' => '2020-01-01',
    ]);
    $grid = [['Name', 'Employee number', 'Sign in'], ['Abebe Kebede', 'E-1', 'maybe']];

    // Read as a list that adds people it is a problem; read as one that completes them it is not.
    expect(loginsApi($tenant, 'POST', 'onboarding/migration/read', ['grid' => $grid])->assertOk()->json('problems'))->toHaveCount(1)
        ->and(loginsApi($tenant, 'POST', 'onboarding/migration/read', ['grid' => $grid, 'mode' => 'complete'])->assertOk()->json('problems'))->toBe([]);

    loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['grid' => $grid, 'mode' => 'complete'])->assertCreated();
    loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'sign_in' => 'maybe']], 'mode' => 'complete'])->assertCreated();
    loginsApi($tenant, 'POST', 'onboarding/migration/rows', ['rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'sign_in' => 'maybe']]])->assertUnprocessable();

    expect(MigrationStagingRow::query()->get()->every(fn (MigrationStagingRow $row): bool => ! array_key_exists('sign_in', $row->raw)))->toBeTrue();
});
