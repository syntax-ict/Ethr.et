<?php

declare(strict_types=1);

use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Exceptions\ImpersonationRestricted;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\MigrationBatch;
use App\Models\PayrollRun;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\Auth\SessionCookie;
use App\Services\EmployeeRelatedRecords;
use App\Services\Payroll\PayrollEngine;
use App\Services\Profile\ProfileUpdateRequestService;
use App\Support\BackgroundWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\StagingFixtures as Staging;

/*
 * An ETHR operator in a support session sees an organisation as its own
 * administrator does, but cannot release its pay or change where it goes:
 * approve or mark paid a run, build the bank file, or write bank details.
 * Every refusal is a 403 problem+json and an audit entry that survives the
 * transaction the refused work was inside.
 *
 * Two layers: the middleware names the actions and fields (and so refuses
 * before any work), the domain code refuses whatever route reaches it.
 */

const SUPPORT_REFUSED = 'admin.tenant.impersonation_refused';
const SUPPORT_ACCOUNT = '1000123456789';

/** @return array{0: Tenant, 1: User, 2: User} the customer, its administrator, the operator */
function supportPayWorld(UserRole $role = UserRole::TENANT_ADMIN): array
{
    $platform = createTenant();
    $operator = createUser(['role' => UserRole::SUPER_ADMIN], $platform);
    $tenant = createTenant();
    $admin = createUser(['role' => $role], $tenant);

    return [$tenant, $admin, $operator];
}

function supportPayToken(User $user, ?User $operator = null): string
{
    $name = $operator instanceof User ? ImpersonationToken::nameFor($operator->id) : 'auth';
    $abilities = $operator instanceof User ? ['*', ImpersonationToken::ABILITY] : ['*'];

    return $user->createToken($name, $abilities, now()->addMinutes(30))->plainTextToken;
}

/** @param  array<string, mixed>  $body */
function supportPayCall(string $token, string $method, Tenant $tenant, string $path, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->json($method, "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}", $body);
}

/** @return list<array<string, mixed>> */
function supportPayRefusals(): array
{
    return DB::table('audit_log')
        ->where('action', SUPPORT_REFUSED)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [
            'tenant_id' => $row->tenant_id,
            'payload' => json_decode((string) $row->payload, true),
        ])
        ->all();
}

function supportPayExpectRefused(TestResponse $response, Tenant $tenant, User $operator, string $actionContains): void
{
    $response->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted')
        ->assertJsonPath('status', 403);

    $refusals = supportPayRefusals();
    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]['tenant_id'])->toBe($tenant->id)
        ->and($refusals[0]['payload']['impersonated_by'])->toBe($operator->id)
        ->and($refusals[0]['payload']['action'])->toContain($actionContains)
        ->and(json_encode($refusals[0]['payload']))->not->toContain(SUPPORT_ACCOUNT);
}

function supportPayRun(Tenant $tenant, string $status, ?User $maker = null): PayrollRun
{
    return PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
        'processed_by' => $maker?->id,
    ]);
}

function supportPayBank(Tenant $tenant, Employee $employee, string $account = '2000111122223'): EmployeeBankDetail
{
    return EmployeeBankDetail::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'bank_name' => 'CBE',
        'branch_name' => 'Bole',
        'account_number' => $account,
        'is_primary' => true,
    ]);
}

// Releasing pay

test('a support session cannot approve a payroll run, and the run is left alone', function () {
    [$tenant, $admin, $operator] = supportPayWorld();
    $maker = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = supportPayRun($tenant, 'completed', $maker);

    $response = supportPayCall(supportPayToken($admin, $operator), 'PUT', $tenant, "payroll/runs/{$run->public_id}/approve");

    supportPayExpectRefused($response, $tenant, $operator, 'PayrollController@approve');
    expect($run->fresh()->status)->toBe('completed');
});

test('the same approval by the administrator themselves still succeeds', function () {
    [$tenant, $admin] = supportPayWorld();
    $maker = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = supportPayRun($tenant, 'completed', $maker);

    supportPayCall(supportPayToken($admin), 'PUT', $tenant, "payroll/runs/{$run->public_id}/approve")
        ->assertOk()->assertJsonPath('status', 'approved');
    expect(supportPayRefusals())->toBe([]);
});

test('a support session cannot mark a run paid', function () {
    [$tenant, $admin, $operator] = supportPayWorld();
    $run = supportPayRun($tenant, 'approved');
    $body = ['paid_on' => now()->toDateString(), 'payment_reference' => 'BANK-1'];

    $response = supportPayCall(supportPayToken($admin, $operator), 'PUT', $tenant, "payroll/runs/{$run->public_id}/paid", $body);

    supportPayExpectRefused($response, $tenant, $operator, 'PayrollController@markPaid');
    expect($run->fresh()->status)->toBe('approved');

    supportPayCall(supportPayToken($admin), 'PUT', $tenant, "payroll/runs/{$run->public_id}/paid", $body)
        ->assertOk()->assertJsonPath('status', 'paid');
});

test('a support session cannot build the bank file in either form', function (string $path, string $action) {
    [$tenant, $admin, $operator] = supportPayWorld();
    $run = supportPayRun($tenant, 'approved');

    $response = supportPayCall(supportPayToken($admin, $operator), 'GET', $tenant, "payroll/runs/{$run->public_id}/{$path}");

    supportPayExpectRefused($response, $tenant, $operator, $action);
    expect(DB::table('audit_log')->where('action', 'payroll.bank_export')->count())->toBe(0);

    supportPayCall(supportPayToken($admin), 'GET', $tenant, "payroll/runs/{$run->public_id}/{$path}")->assertOk();
})->with([
    'json' => ['export/bank', 'PayrollController@bankExport'],
    'csv' => ['export/bank-csv', 'PayrollController@downloadBankExport'],
]);

// Writing bank details

test('a support session cannot add a bank detail', function () {
    [$tenant, $admin, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $body = ['bank_name' => 'Dashen Bank', 'account_number' => SUPPORT_ACCOUNT, 'is_primary' => true];

    $response = supportPayCall(supportPayToken($admin, $operator), 'POST', $tenant, "employees/{$employee->public_id}/bank-details", $body);

    supportPayExpectRefused($response, $tenant, $operator, 'BankDetailController@store');
    expect($employee->bankDetails()->count())->toBe(0);

    supportPayCall(supportPayToken($admin), 'POST', $tenant, "employees/{$employee->public_id}/bank-details", $body)->assertCreated();
});

test('a support session cannot change a bank detail or move the primary flag', function () {
    [$tenant, $admin, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $primary = supportPayBank($tenant, $employee);
    $other = EmployeeBankDetail::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'bank_name' => 'Awash',
        'account_number' => '3000333344445', 'is_primary' => false,
    ]);

    $response = supportPayCall(supportPayToken($admin, $operator), 'PUT', $tenant, "employees/{$employee->public_id}/bank-details/{$other->public_id}", [
        'bank_name' => 'Awash', 'account_number' => SUPPORT_ACCOUNT, 'is_primary' => true,
    ]);

    supportPayExpectRefused($response, $tenant, $operator, 'BankDetailController@update');
    expect($primary->fresh()->is_primary)->toBeTrue()
        ->and($other->fresh()->is_primary)->toBeFalse()
        ->and($other->fresh()->account_number)->toBe('3000333344445');
});

test('a support session cannot file a bank change on the employee profile', function () {
    [$tenant, , $operator] = supportPayWorld();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $user->id]);

    $response = supportPayCall(supportPayToken($user, $operator), 'PUT', $tenant, 'profile', ['bank_account_number' => SUPPORT_ACCOUNT]);

    supportPayExpectRefused($response, $tenant, $operator, 'ProfileController@update');
    expect(supportPayRefusals()[0]['payload']['fields'])->toBe(['bank_account_number'])
        ->and(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(0);

    supportPayCall(supportPayToken($user), 'PUT', $tenant, 'profile', ['bank_account_number' => SUPPORT_ACCOUNT])->assertOk();
    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

// The backstop: a route the middleware does not name

function supportPayStagedBankChange(Tenant $tenant, Employee $employee, User $requester): ProfileUpdateRequest
{
    test()->actingAs($requester);

    return app(ProfileUpdateRequestService::class)
        ->stage($employee, $requester, ['bank_account_number' => SUPPORT_ACCOUNT])
        ->firstOrFail();
}

test('approving a staged bank change in a support session is refused and writes nothing', function (string $route) {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $primary = supportPayBank($tenant, $employee);
    $staged = supportPayStagedBankChange($tenant, $employee, $requester);
    $token = supportPayToken($hr, $operator);

    $response = $route === 'review'
        ? supportPayCall($token, 'POST', $tenant, "profile-update-requests/{$staged->public_id}/review", ['action' => 'approve'])
        : supportPayCall($token, 'POST', $tenant, 'approvals/batch', ['actions' => [
            ['type' => 'profile_update', 'public_id' => $staged->public_id, 'action' => 'approve'],
        ]]);

    $response->assertForbidden()->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');
    expect($staged->fresh()->status)->toBe(ProfileUpdateStatus::PENDING)
        ->and($primary->fresh()->account_number)->toBe('2000111122223')
        ->and($primary->fresh()->is_primary)->toBeTrue()
        ->and($employee->bankDetails()->count())->toBe(1);

    $refusals = supportPayRefusals();
    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]['tenant_id'])->toBe($tenant->id)
        ->and($refusals[0]['payload']['impersonated_by'])->toBe($operator->id);
})->with(['review', 'batch']);

test('the same approval by HR themselves applies the change', function () {
    [$tenant, $hr] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $primary = supportPayBank($tenant, $employee);
    $staged = supportPayStagedBankChange($tenant, $employee, $requester);

    supportPayCall(supportPayToken($hr), 'POST', $tenant, "profile-update-requests/{$staged->public_id}/review", ['action' => 'approve'])->assertOk();

    expect($primary->fresh()->account_number)->toBe(SUPPORT_ACCOUNT);
});

test('the model and the engine refuse on their own, whatever route reached them', function () {
    [$tenant, $admin, $operator] = supportPayWorld();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $detail = supportPayBank($tenant, $employee);
    $run = supportPayRun($tenant, 'completed');

    $token = $admin->createToken(ImpersonationToken::nameFor($operator->id), ['*', ImpersonationToken::ABILITY])->accessToken;
    $session = User::query()->findOrFail($admin->id)->withAccessToken($token);
    auth()->setUser($session);

    $attempts = [
        'create' => fn () => EmployeeBankDetail::create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'bank_name' => 'X', 'account_number' => '1']),
        'update' => fn () => $detail->update(['account_number' => '9']),
        'delete' => fn () => $detail->delete(),
        'approve' => fn () => app(PayrollEngine::class)->approve($run, $admin->id),
        'paid' => fn () => app(PayrollEngine::class)->markPaid($run, $admin->id, now()->toDateString(), null, null),
        'addBankDetail' => fn () => app(EmployeeRelatedRecords::class)->addBankDetail($employee, ['bank_name' => 'X', 'account_number' => '1', 'is_primary' => true]),
    ];

    foreach ($attempts as $name => $attempt) {
        expect($attempt)->toThrow(ImpersonationRestricted::class, '', "{$name} was not refused");
    }

    expect($detail->fresh()->account_number)->toBe('2000111122223')
        ->and($detail->fresh()->is_primary)->toBeTrue()
        ->and($employee->bankDetails()->count())->toBe(1)
        ->and($run->fresh()->status)->toBe('completed');

    BackgroundWork::run(function () use ($detail): void {
        expect(ImpersonationToken::sessionActive())->toBeFalse();
        $detail->update(['bank_name' => 'Queue bank']);
    });
    expect($detail->fresh()->bank_name)->toBe('Queue bank');
});

// The workforce migration

test('a support session cannot stage a list that carries bank data, and may stage one that does not', function () {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $row = ['name' => 'Selam Bekele', 'hire_date' => '2026-01-15'];

    $response = supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, 'onboarding/migration/rows', [
        'rows' => [[...$row, 'bank_name' => 'CBE', 'bank_account' => SUPPORT_ACCOUNT]],
    ]);

    supportPayExpectRefused($response, $tenant, $operator, 'MigrationController@stageFromRows');
    expect(MigrationBatch::query()->count())->toBe(0);

    supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, 'onboarding/migration/rows', ['rows' => [$row]])->assertCreated();
});

test('a support session cannot commit a list whose staged rows carry bank data', function () {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $batch = Staging::stage($tenant, [
        ['name' => 'Selam Bekele', 'hire_date' => '2026-01-15', 'bank_name' => 'CBE', 'bank_account' => SUPPORT_ACCOUNT],
        ['name' => 'Hana Bekele', 'hire_date' => '2026-01-15'],
    ]);

    $response = supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, "onboarding/migration/batches/{$batch->public_id}/commit");

    supportPayExpectRefused($response, $tenant, $operator, 'commitSlice');
    expect($batch->fresh()->status)->not->toBe('committed')
        ->and(Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Hana Bekele')->exists())->toBeFalse()
        ->and(EmployeeBankDetail::query()->count())->toBe(0);

    supportPayCall(supportPayToken($hr), 'POST', $tenant, "onboarding/migration/batches/{$batch->public_id}/commit")->assertOk();
    expect(EmployeeBankDetail::query()->count())->toBe(1);
});

test('a support session may commit a list with no bank data', function () {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $batch = Staging::stage($tenant, [['name' => 'Hana Bekele', 'hire_date' => '2026-01-15']]);

    supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, "onboarding/migration/batches/{$batch->public_id}/commit")->assertOk();

    expect(Employee::query()->where('tenant_id', $tenant->id)->where('name', 'Hana Bekele')->exists())->toBeTrue()
        ->and(supportPayRefusals())->toBe([]);
});

// Through the real sign-in-as flow and the browser cookie

test('the browser session started by the real impersonate endpoint is refused too', function () {
    $secret = 'JBSWY3DPEHPK3PXP';
    $platform = createTenant();
    $operator = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true, 'mfa_secret' => $secret], $platform);
    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $maker = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = supportPayRun($tenant, 'completed', $maker);
    $spa = ['Origin' => 'http://localhost:3000'];

    $started = supportPayCall($operator->createToken('auth', ['*'])->plainTextToken, 'POST', $platform, "admin/tenants/{$tenant->public_id}/impersonate", [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();
    $plain = (string) $started->json('token');

    app('auth')->forgetGuards();
    $response = test()->flushHeaders()->withHeaders($spa)
        ->withCredentials()
        ->withUnencryptedCookie(SessionCookie::NAME, $plain)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve");

    $response->assertForbidden()->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');
    expect($run->fresh()->status)->toBe('completed');

    $refusals = collect(supportPayRefusals());
    expect($refusals->last()['payload']['impersonated_by'])->toBe($operator->id)
        ->and($refusals->last()['tenant_id'])->toBe($tenant->id);
});

// Not over-broad

test('a support session can still make an ordinary write', function () {
    [$tenant, $admin, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    supportPayCall(supportPayToken($admin, $operator), 'PUT', $tenant, "employees/{$employee->public_id}", ['phone' => '0922334455'])
        ->assertOk();

    expect($employee->fresh()->phone)->toBe('+251922334455')
        ->and(supportPayRefusals())->toBe([]);
});

test('a batch with a bank change in it is refused whole, so no item before it is applied', function () {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($requester);
    $staged = app(ProfileUpdateRequestService::class)
        ->stage($employee, $requester, ['name' => 'Kebede Abebe', 'bank_account_number' => SUPPORT_ACCOUNT]);
    expect($staged)->toHaveCount(2);

    $response = supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, 'approvals/batch', [
        'actions' => $staged->map(fn (ProfileUpdateRequest $request): array => [
            'type' => 'profile_update', 'public_id' => $request->public_id, 'action' => 'approve',
        ])->all(),
    ]);

    $response->assertForbidden()->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');
    expect(ProfileUpdateRequest::where('employee_id', $employee->id)->where('status', ProfileUpdateStatus::PENDING->value)->count())->toBe(2)
        ->and($employee->fresh()->name)->toBe('Abebe Kebede')
        ->and(supportPayRefusals())->toHaveCount(1);
});

test('the refusal is kept even when the work it interrupted was inside a transaction', function () {
    [$tenant, $admin, $operator] = supportPayWorld();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    auth()->setUser(User::query()->findOrFail($admin->id)->withAccessToken(
        $admin->createToken(ImpersonationToken::nameFor($operator->id), ['*', ImpersonationToken::ABILITY])->accessToken,
    ));

    $refusal = null;
    try {
        DB::transaction(fn () => EmployeeBankDetail::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'bank_name' => 'X', 'account_number' => SUPPORT_ACCOUNT,
        ]));
    } catch (ImpersonationRestricted $caught) {
        $refusal = $caught;
    }

    expect($refusal)->toBeInstanceOf(ImpersonationRestricted::class)
        ->and(supportPayRefusals())->toBe([]);

    $response = $refusal->render(Request::create('/api/v1/anything', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));

    expect($response->getStatusCode())->toBe(403)
        ->and($employee->bankDetails()->count())->toBe(0);
    $rows = supportPayRefusals();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['tenant_id'])->toBe($tenant->id)
        ->and($rows[0]['payload']['impersonated_by'])->toBe($operator->id)
        ->and($rows[0]['payload']['action'])->toBe('EmployeeBankDetail::saving');
});

test('a support session cannot stage bank data through a grid read, and the refusal stages nothing', function () {
    [$tenant, $hr, $operator] = supportPayWorld(UserRole::HR_ADMIN);
    $grid = [
        ['Name', 'Hire date', 'Bank name', 'Bank account'],
        ['Selam Bekele', '2026-01-15', 'CBE', SUPPORT_ACCOUNT],
    ];

    $response = supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, 'onboarding/migration/rows', ['grid' => $grid]);

    supportPayExpectRefused($response, $tenant, $operator, 'MigrationController@stageFromRows');
    expect(MigrationBatch::query()->count())->toBe(0);

    supportPayCall(supportPayToken($hr, $operator), 'POST', $tenant, 'onboarding/migration/rows', [
        'grid' => [['Name', 'Hire date'], ['Selam Bekele', '2026-01-15']],
    ])->assertCreated();
});
