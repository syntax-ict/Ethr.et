<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AccountActivationCode;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActivationCodeService;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/*
 * A sheet of activation codes (One employee identity, PR 9): one code for
 * each of many employees no invitation can reach, issued in one request and
 * printed as slips. Every person is held to what a single issue holds them
 * to; the sheet may live a week; and someone whose emailed invitation went
 * unanswered for three days may be given a code too.
 */

/**
 * An organisation that signs in by employee code, an HR admin, and employees
 * with no email and no phone.
 *
 * @return array{0: Tenant, 1: User, 2: list<Employee>}
 */
function codeSheetFixture(int $people = 3, array $settings = ['login_identifiers' => ['employee_code', 'email']]): array
{
    $tenant = createTenant(['subdomain' => 'acme', 'settings' => $settings]);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employees = [];
    foreach (range(1, $people) as $n) {
        $employees[] = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => null,
            'phone' => null,
            'employee_code' => sprintf('EMP-%04d', $n),
        ]);
    }

    return [$tenant, $hr, $employees];
}

/**
 * @param  list<Employee>  $employees
 * @return array<string, mixed>
 */
function codeSheetPayload(array $employees, array $overrides = []): array
{
    return [
        'employee_ids' => array_map(fn (Employee $employee): string => (string) $employee->public_id, $employees),
        'attested' => true,
        'current_password' => 'password',
        ...$overrides,
    ];
}

const CODE_SHEET_URL = '/api/v1/employees/activation-codes';

it('issues a code for each person, once, never cached, and records one sheet', function () {
    Carbon::setTestNow('2026-11-12 08:00:00');
    [$tenant, , $employees] = codeSheetFixture();

    $response = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))
        ->assertCreated()
        ->assertJsonPath('issued', 3)
        ->assertJsonPath('refused', [])
        ->assertJsonPath('codes.0.employee_code', 'EMP-0001')
        ->assertJsonPath('codes.2.name', $employees[2]->name);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $codes = array_map(fn (array $slip): string => str_replace('-', '', $slip['code']), $response->json('codes'));
    expect(array_unique($codes))->toHaveCount(3);
    foreach ($codes as $index => $code) {
        expect(ActivationCodeService::normalise($code))->toBe($code)
            ->and($response->json("codes.{$index}.activation_url"))->toContain('#c='.$code.'&e=EMP-000'.($index + 1));
    }

    // One sheet, three days by default, nothing readable kept.
    $rows = AccountActivationCode::where('tenant_id', $tenant->id)->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('issued_batch')->unique()->all())->toBe([$response->json('batch')])
        ->and($rows->pluck('document_type')->unique()->all())->toBe([ActivationCodeService::AT_HANDOVER])
        ->and($rows->first()->expires_at->toDateTimeString())->toBe('2026-11-15 08:00:00')
        ->and($response->json('expires_at'))->toStartWith('2026-11-15T08:00:00');

    expect(AuditLog::where('action', 'user.activation_codes_issued')->count())->toBe(1)
        ->and(AuditLog::where('action', 'user.activation_code_issued')->count())->toBe(3);
    $logged = (string) json_encode(AuditLog::whereIn('action', ['user.activation_codes_issued', 'user.activation_code_issued'])->get()->toArray());
    foreach ($codes as $code) {
        expect($logged)->not->toContain($code);
    }

    // Each person has an account waiting, and no email.
    expect(User::where('tenant_id', $tenant->id)->whereIn('employee_id', array_map(fn ($e) => $e->id, $employees))->where('status', 'invited')->count())->toBe(3);
});

it('lets each person redeem their own code, once', function () {
    [, , $employees] = codeSheetFixture(2);
    $codes = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))->assertCreated()->json('codes');

    $redeem = function (string $employeeCode, string $code) {
        app(CurrentTenant::class)->forget();
        app('auth')->forgetGuards();

        return test()->postJson('/api/v1/auth/activation/redeem', [
            'tenant' => 'acme',
            'employee_code' => $employeeCode,
            'code' => $code,
            'password' => 'a-fresh-secret-9',
            'password_confirmation' => 'a-fresh-secret-9',
        ]);
    };

    // Somebody else's slip is no use.
    $redeem('EMP-0001', $codes[1]['code'])->assertStatus(422);
    $redeem('EMP-0002', $codes[1]['code'])->assertOk();
    $redeem('EMP-0002', $codes[1]['code'])->assertStatus(422);

    expect(User::where('employee_id', $employees[1]->id)->value('status'))->toBe('active')
        ->and(User::where('employee_id', $employees[0]->id)->value('status'))->toBe('invited');
});

it('refuses the people a single issue would refuse, says why, and issues the rest', function () {
    [$tenant, , $employees] = codeSheetFixture(2);
    $withEmail = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'reachable@acme.et', 'phone' => null]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $adminEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null, 'user_id' => $admin->id]);

    $response = $this->postJson(CODE_SHEET_URL, codeSheetPayload([$employees[0], $withEmail, $adminEmployee, $employees[1]]))
        ->assertCreated()
        ->assertJsonPath('issued', 2);

    expect(array_column($response->json('codes'), 'employee_public_id'))->toBe([$employees[0]->public_id, $employees[1]->public_id])
        ->and(array_column($response->json('refused'), 'reason', 'employee_public_id'))->toBe([
            $withEmail->public_id => 'contact',
            $adminEmployee->public_id => 'role',
        ])
        ->and($response->json('refused.0.detail'))->toContain('can be reached by email');
});

it('refuses everyone, with what to do, when the organisation has no way for them to sign in', function () {
    [, , $employees] = codeSheetFixture(2, ['login_identifiers' => ['email']]);

    $response = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))
        ->assertCreated()
        ->assertJsonPath('issued', 0)
        ->assertJsonPath('batch', null)
        ->assertJsonPath('codes', []);

    expect(array_unique(array_column($response->json('refused'), 'reason')))->toBe(['identifier'])
        ->and($response->json('refused.0.detail'))->toContain('Turn on employee code sign-in')
        ->and(AccountActivationCode::count())->toBe(0);
});

it('lets a sheet live up to a week, and no longer', function () {
    Carbon::setTestNow('2026-11-12 08:00:00');
    [, , $employees] = codeSheetFixture(1);

    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['hours' => 168]))->assertCreated();
    expect(AccountActivationCode::latest('id')->first()->expires_at->toDateTimeString())->toBe('2026-11-19 08:00:00');

    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['hours' => 169]))
        ->assertStatus(422)->assertJsonValidationErrors(['hours']);
});

it('withdraws an earlier code when a newer sheet covers the same person', function () {
    [, , $employees] = codeSheetFixture(1);

    $first = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))->assertCreated()->json('codes.0.code');
    $second = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))->assertCreated()->json('codes.0.code');

    expect($second)->not->toBe($first)
        ->and(AccountActivationCode::whereNull('revoked_at')->count())->toBe(1)
        ->and(AccountActivationCode::whereNotNull('revoked_at')->count())->toBe(1);
});

it('needs the undertaking, the HR password, and the permission', function () {
    [, , $employees] = codeSheetFixture(1);

    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['attested' => false]))
        ->assertStatus(422)->assertJsonValidationErrors(['attested']);
    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['current_password' => 'not-it']))
        ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    expect(AccountActivationCode::count())->toBe(0);

    actingAsUser(['role' => UserRole::EMPLOYEE], app(CurrentTenant::class)->get());
    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees))->assertForbidden();
});

it('covers at most two hundred people', function () {
    [, , $employees] = codeSheetFixture(1);
    $ids = array_map(fn (int $n): string => sprintf('01HZ%022d', $n), range(1, ActivationCodeService::SHEET_MAX + 1));

    $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['employee_ids' => $ids]))
        ->assertStatus(422)->assertJsonValidationErrors(['employee_ids']);
});

it('answers another organisation\'s employee as one that does not exist, and issues nothing', function () {
    $other = createTenant(['subdomain' => 'other']);
    $theirs = Employee::factory()->create(['tenant_id' => $other->id, 'email' => null, 'phone' => null]);
    app()->forgetScopedInstances();
    [, , $employees] = codeSheetFixture(1);

    $unknown = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['employee_ids' => [$employees[0]->public_id, '01HZZZZZZZZZZZZZZZZZZZZZZZ']]))
        ->assertStatus(422)->json('errors.employee_ids');
    $foreign = $this->postJson(CODE_SHEET_URL, codeSheetPayload($employees, ['employee_ids' => [$employees[0]->public_id, $theirs->public_id]]))
        ->assertStatus(422)->json('errors.employee_ids');

    expect($foreign)->toBe($unknown)
        ->and(AccountActivationCode::withoutGlobalScope('tenant')->count())->toBe(0);
});

describe('an invitation nobody answered', function () {
    /** An employee with an email whose login was invited `$daysAgo` days ago. */
    $invited = function (Tenant $tenant, int $daysAgo): Employee {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'wrong@acme.et', 'phone' => null, 'employee_code' => 'EMP-0900']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
            'email' => 'wrong@acme.et',
            'status' => 'invited',
            'employee_id' => $employee->id,
            'invited_at' => now()->subDays($daysAgo),
        ]);
        $employee->update(['user_id' => $user->id]);

        return $employee->refresh();
    };

    it('may be given a code after three days, on a sheet or one at a time', function () use ($invited) {
        [$tenant] = codeSheetFixture(0);
        $employee = $invited($tenant, 4);

        $this->postJson(CODE_SHEET_URL, codeSheetPayload([$employee]))
            ->assertCreated()
            ->assertJsonPath('issued', 1);

        $this->postJson("/api/v1/employees/{$employee->public_id}/activation-code", [
            'attested' => true, 'document_type' => 'kebele_id', 'current_password' => 'password',
        ])->assertCreated();
    });

    it('is still an invitation to wait for before that', function () use ($invited) {
        [$tenant] = codeSheetFixture(0);
        $employee = $invited($tenant, 2);

        $this->postJson(CODE_SHEET_URL, codeSheetPayload([$employee]))
            ->assertCreated()
            ->assertJsonPath('issued', 0)
            ->assertJsonPath('refused.0.reason', 'contact');
    });

    it('waits again after a reminder', function () use ($invited) {
        [$tenant, $hr] = codeSheetFixture(0);
        $employee = $invited($tenant, 10);
        InvitationSend::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $employee->user_id,
            'batch' => '01HZBATCH00000000000000001',
            'channel' => InvitationSend::EMAIL,
            'status' => InvitationSend::SENT,
            'reminder' => true,
            'queued_by' => $hr->id,
            'queued_at' => now()->subDay(),
            'sent_at' => now()->subDay(),
        ]);

        $this->postJson(CODE_SHEET_URL, codeSheetPayload([$employee]))
            ->assertCreated()
            ->assertJsonPath('refused.0.reason', 'contact');
    });

    it('never applies to someone who has activated', function () use ($invited) {
        [$tenant] = codeSheetFixture(0);
        $employee = $invited($tenant, 30);
        User::whereKey($employee->user_id)->update(['status' => 'active']);

        $this->postJson(CODE_SHEET_URL, codeSheetPayload([$employee]))
            ->assertCreated()
            ->assertJsonPath('refused.0.reason', 'contact');
    });
});
