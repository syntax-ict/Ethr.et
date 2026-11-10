<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationStagingRow;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Identity\EmployeeIntake;
use App\Services\Migration\WorkforceMigrationService;
use Tests\Support\IntakeFixtures;

/*
 * One employee record per person, whichever way they arrive.
 *
 * EmployeeIntake is the only code that makes an employee, and it asks first
 * whether the organisation already has them. These tests are about what each
 * way in does with the answer: the form refuses and names the person, or asks
 * the person adding to confirm; the file import refuses the row; a staged list
 * links the row to the record; a hire is refused.
 *
 * ScimSsoAttachTest covers the two directories, EmployeeIntakeConcurrencyTest
 * the lock, and Security/EmployeeCreationInventoryTest that there is no other
 * way in.
 */

/** @return array{0: Tenant, 1: User} */
function intakeOrganisation(UserRole $role = UserRole::HR_ADMIN): array
{
    $tenant = createTenant();

    return [$tenant, actingAsUser(['role' => $role], $tenant)];
}

function intakeAbebe(Tenant $tenant, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
        'email' => 'abebe@example.et',
        'phone' => '+251911223344',
        ...$attributes,
    ]);
}

function intakeHeadcount(Tenant $tenant): int
{
    return Employee::withTrashed()->where('tenant_id', $tenant->id)->count();
}

// ── The form and POST /employees ────────────────────────────────────────────

describe('adding one employee', function () {
    it('adds someone nobody knows, and records that the form made the record', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'Sara Tesfaye',
            'email' => 'sara@example.et',
            'phone' => '0922334455',
            'hire_date' => '2026-01-05',
        ])->assertCreated();

        $sara = Employee::where('public_id', $response->json('public_id'))->firstOrFail();
        expect($sara->created_via)->toBe(EmployeeIntake::VIA_MANUAL)
            ->and($response->json())->not->toHaveKey('created_via');

        $audit = AuditLog::where('action', 'employee.created')->where('auditable_id', $sara->id)->firstOrFail();
        expect($audit->payload['via'])->toBe('manual')
            ->and($audit->payload)->not->toHaveKey('confirmed_new_over');
    });

    it('refuses a person who is already an employee, and names them', function () {
        [$tenant] = intakeOrganisation();
        $abebe = intakeAbebe($tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'A. Kebede',
            'email' => 'Abebe@Example.et',
            'hire_date' => '2026-01-05',
        ]);

        $response->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://ethr.et/errors/employee-exists')
            ->assertJsonPath('code', 'employee-exists')
            ->assertJsonPath('outcome', 'matched')
            ->assertJsonPath('candidates.0.public_id', $abebe->public_id)
            ->assertJsonPath('candidates.0.name', 'Abebe Kebede')
            ->assertJsonPath('detail', __('employee.exists', ['name' => 'Abebe Kebede']))
            ->assertJsonMissingPath('candidates.0.id');

        expect(intakeHeadcount($tenant))->toBe(1);
    });

    it('does not let "this is someone new" overrule a certain match', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'A. Kebede',
            'email' => 'abebe@example.et',
            'hire_date' => '2026-01-05',
            'confirm_new' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'employee-exists');

        expect(intakeHeadcount($tenant))->toBe(1);
    });

    it('finds 0911 on the form as +251911 on the record', function () {
        [$tenant] = intakeOrganisation();
        $abebe = intakeAbebe($tenant, ['email' => null]);

        $this->postJson('/api/v1/employees', [
            'name' => 'Abebe Kebede',
            'phone' => '0911223344',
            'hire_date' => '2026-01-05',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'employee-exists')
            ->assertJsonPath('candidates.0.public_id', $abebe->public_id);

        expect(intakeHeadcount($tenant))->toBe(1);
    });

    it('treats an equal national ID as the same person, whatever else differs', function () {
        [$tenant] = intakeOrganisation();
        $abebe = intakeAbebe($tenant, ['national_id' => '1234567890123456']);

        $this->postJson('/api/v1/employees', [
            'name' => 'Someone Else',
            'email' => 'else@example.et',
            'national_id' => '1234567890123456',
            'hire_date' => '2026-01-05',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'employee-exists')
            ->assertJsonPath('candidates.0.public_id', $abebe->public_id);
    });

    it('asks before adding someone who shares only a phone, and adds them once a person confirms', function () {
        [$tenant] = intakeOrganisation();
        $abebe = intakeAbebe($tenant);

        $sara = ['name' => 'Sara Tesfaye', 'phone' => '0911223344', 'hire_date' => '2026-01-05'];

        $this->postJson('/api/v1/employees', $sara)
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://ethr.et/errors/employee-needs-confirmation')
            ->assertJsonPath('code', 'employee-needs-confirmation')
            ->assertJsonPath('outcome', 'needs_review')
            ->assertJsonPath('candidates.0.public_id', $abebe->public_id)
            ->assertJsonPath('detail', __('employee.exists_probable', ['name' => 'Abebe Kebede']));
        expect(intakeHeadcount($tenant))->toBe(1);

        $created = $this->postJson('/api/v1/employees', [...$sara, 'confirm_new' => true])->assertCreated();
        expect(intakeHeadcount($tenant))->toBe(2);

        // Who the person was shown before they said "someone new" is kept.
        $record = Employee::where('public_id', $created->json('public_id'))->firstOrFail();
        $audit = AuditLog::where('action', 'employee.created')->where('auditable_id', $record->id)->firstOrFail();
        expect($audit->payload['confirmed_new_over'])->toBe([$abebe->public_id]);
    });

    it('asks before adding someone who looks like a leaver, and says they worked here', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant, ['status' => EmployeeStatus::RESIGNED, 'employee_code' => 'OLD-1']);

        $back = ['name' => 'Abebe Kebede', 'email' => 'abebe@example.et', 'hire_date' => '2026-01-05'];

        $this->postJson('/api/v1/employees', $back)
            ->assertStatus(409)
            ->assertJsonPath('code', 'employee-needs-confirmation')
            ->assertJsonPath('outcome', 'former_employee')
            ->assertJsonPath('detail', __('employee.exists_former', ['name' => 'Abebe Kebede']));

        // Rehire stays as it was: a leaver may be given a new record.
        $this->postJson('/api/v1/employees', [...$back, 'confirm_new' => true])->assertCreated();
        expect(intakeHeadcount($tenant))->toBe(2);
    });

    it('names a deleted record, which can be restored', function () {
        [$tenant] = intakeOrganisation(UserRole::TENANT_ADMIN);
        $abebe = intakeAbebe($tenant);
        $abebe->delete();

        $again = ['name' => 'Abebe Kebede', 'email' => 'abebe@example.et', 'hire_date' => '2026-01-05'];

        $this->postJson('/api/v1/employees', $again)
            ->assertStatus(409)
            ->assertJsonPath('code', 'employee-needs-confirmation')
            ->assertJsonPath('outcome', 'archived')
            ->assertJsonPath('candidates.0.public_id', $abebe->public_id)
            ->assertJsonPath('detail', __('employee.exists_archived', ['name' => 'Abebe Kebede']));

        $this->postJson("/api/v1/employees/{$abebe->public_id}/restore")
            ->assertOk()
            ->assertJsonPath('public_id', $abebe->public_id);

        expect($abebe->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::where('action', 'employee.restored')->where('auditable_id', $abebe->id)->exists())->toBeTrue();

        // Restored, the person is simply here.
        $this->postJson('/api/v1/employees', $again)->assertStatus(409)->assertJsonPath('code', 'employee-exists');
        expect(intakeHeadcount($tenant))->toBe(1);

        // Restoring twice is answered as done, not as an error.
        $this->postJson("/api/v1/employees/{$abebe->public_id}/restore")->assertOk();
    });

    it('lets only someone who may delete restore, and counts the seat', function () {
        [$tenant] = intakeOrganisation(UserRole::EMPLOYEE);
        $abebe = intakeAbebe($tenant);
        $abebe->delete();

        $this->postJson("/api/v1/employees/{$abebe->public_id}/restore")->assertForbidden();
        expect($abebe->fresh()->trashed())->toBeTrue();

        // The plan is full: bringing a person back takes a seat like adding one.
        Subscription::factory()->for($tenant)->create(['plan_id' => Plan::factory()->create(['max_employees' => 1])->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id]);
        app('auth')->forgetGuards();
        $this->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

        $this->postJson("/api/v1/employees/{$abebe->public_id}/restore")->assertForbidden();
        expect($abebe->fresh()->trashed())->toBeTrue();
    });

    it('says "already here" before "the plan is full"', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant);
        Subscription::factory()->for($tenant)->create(['plan_id' => Plan::factory()->create(['max_employees' => 1])->id]);

        // A duplicate takes no seat, so the limit is not the answer to it.
        $this->postJson('/api/v1/employees', ['name' => 'Abebe K', 'email' => 'abebe@example.et', 'hire_date' => '2026-01-05'])
            ->assertStatus(409);

        $this->postJson('/api/v1/employees', ['name' => 'Sara Tesfaye', 'hire_date' => '2026-01-05'])
            ->assertForbidden();
    });

    it('does not see another organisation\'s employee', function () {
        $other = createTenant();
        intakeAbebe($other);

        [$tenant] = intakeOrganisation();

        $this->postJson('/api/v1/employees', [
            'name' => 'Abebe Kebede',
            'email' => 'abebe@example.et',
            'phone' => '+251911223344',
            'hire_date' => '2026-01-05',
        ])->assertCreated();

        expect(intakeHeadcount($tenant))->toBe(1);
    });

    it('records that an API key made the record', function () {
        $tenant = createTenant();
        $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $plain = 'ethr_'.bin2hex(random_bytes(20));
        ApiKey::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $admin->id,
            'key_hash' => hash('sha256', $plain),
            'key_prefix' => substr($plain, 0, 12),
            'abilities' => ['write'],
        ]);
        app(CurrentTenant::class)->forget();

        $response = $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/employees', ['name' => 'From A System', 'hire_date' => '2026-01-05'])
            ->assertCreated();

        expect(Employee::withoutGlobalScopes()->where('public_id', $response->json('public_id'))->value('created_via'))
            ->toBe(EmployeeIntake::VIA_API);
    });
});

// ── The file import ─────────────────────────────────────────────────────────

describe('the file import', function () {
    it('refuses a row that may be an existing employee, names them, and adds nobody', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant);

        $response = $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'likeness',
            'rows' => [
                ['name' => 'Sara Tesfaye', 'phone' => '0911223344', 'hire_date' => '2026-01-05'],
                ['name' => 'Dawit Alemu', 'phone' => '0933445566', 'hire_date' => '2026-01-05'],
            ],
        ])->assertCreated();

        expect($response->json('created'))->toBe(1)
            ->and($response->json('matched'))->toBe(0)
            ->and($response->json('errors.0.0'))->toBe(__('employee.import_possible_duplicate', ['row' => 0, 'name' => 'Abebe Kebede']))
            ->and(intakeHeadcount($tenant))->toBe(2);

        expect(Employee::where('tenant_id', $tenant->id)->where('name', 'Dawit Alemu')->value('created_via'))
            ->toBe(EmployeeIntake::VIA_IMPORT);
    });

    it('adds nobody when the same people are imported a second time', function () {
        [$tenant] = intakeOrganisation();

        $rows = [
            ['name' => 'Sara Tesfaye', 'email' => 'sara@example.et', 'phone' => '0922334455', 'hire_date' => '2026-01-05'],
            ['name' => 'Dawit Alemu', 'phone' => '0933445566', 'national_id' => '9876543210987654', 'hire_date' => '2026-01-05'],
        ];

        $this->postJson('/api/v1/employees/import/commit', ['import_key' => 'first', 'rows' => $rows])
            ->assertCreated()->assertJsonPath('created', 2);

        // A re-exported file: a new key, the phones written the other way.
        $rows[0]['phone'] = '+251922334455';
        $rows[1]['phone'] = '+251933445566';

        $this->postJson('/api/v1/employees/import/commit', ['import_key' => 'second', 'rows' => $rows])
            ->assertSuccessful()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('matched', 2);

        expect(intakeHeadcount($tenant))->toBe(2);
    });
});

// ── The staged list ─────────────────────────────────────────────────────────

describe('a staged list', function () {
    it('makes one record of a person the list names twice', function () {
        [$tenant] = intakeOrganisation();
        $service = app(WorkforceMigrationService::class);

        // Neither row matched anyone when the list was staged: nobody was
        // there. The second is asked again at commit, after the first exists.
        $batch = $service->stageFromRows([
            ['name' => 'Hanna Tesfaye', 'phone' => '0911000111', 'hire_date' => '2026-01-05'],
            ['name' => 'Hanna Tesfaye', 'phone' => '+251911000111', 'hire_date' => '2026-01-05'],
        ], $tenant->id);

        expect($batch->rows()->pluck('action')->all())->toBe(['create', 'create']);

        $totals = $service->commit($batch);

        expect($totals['created'])->toBe(1)
            ->and($totals['merged'])->toBe(1)
            ->and(intakeHeadcount($tenant))->toBe(1)
            ->and($batch->rows()->pluck('resolved_employee_id')->unique()->count())->toBe(1);

        expect(Employee::where('tenant_id', $tenant->id)->value('created_via'))->toBe(EmployeeIntake::VIA_MIGRATION);
    });

    it('links a row to a person who was added while the list waited', function () {
        [$tenant] = intakeOrganisation();
        $service = app(WorkforceMigrationService::class);

        $batch = $service->stageFromRows([
            ['name' => 'Abebe Kebede', 'email' => 'abebe@example.et', 'hire_date' => '2026-01-05'],
        ], $tenant->id);
        expect($batch->rows()->first()->action)->toBe('create');

        $abebe = intakeAbebe($tenant);

        $totals = $service->commit($batch);

        expect($totals['created'])->toBe(0)
            ->and($totals['merged'])->toBe(1)
            ->and(intakeHeadcount($tenant))->toBe(1)
            ->and($batch->rows()->first()->resolved_employee_id)->toBe($abebe->id);
    });

    it('keeps a reviewer\'s "create" over a likeness they were shown', function () {
        [$tenant] = intakeOrganisation();
        intakeAbebe($tenant);
        $service = app(WorkforceMigrationService::class);

        $batch = $service->stageFromRows([
            ['name' => 'Sara Tesfaye', 'phone' => '0911223344', 'hire_date' => '2026-01-05'],
        ], $tenant->id);

        $row = $batch->rows()->first();
        expect($row->match_outcome)->toBe('probable')
            ->and($row->action)->toBe(MigrationStagingRow::ACTION_DEFER);

        $service->setAction($row, MigrationStagingRow::ACTION_CREATE);
        $totals = $service->commit($batch);

        expect($totals['created'])->toBe(1)
            ->and(intakeHeadcount($tenant))->toBe(2);
    });

    it('records that a terminal\'s roster made the record', function () {
        [$tenant] = intakeOrganisation();
        $service = app(WorkforceMigrationService::class);

        // As stageFromDevice() stages a person the terminal holds. The mock
        // terminal's roster is the organisation's own employees, so it has
        // nobody new to offer.
        $batch = $service->stageFromRows([[
            'device_user_id' => '77',
            'name' => 'Marta Girma',
            'employee_code' => '77',
            'badge_number' => '77',
            'source_type' => 'mock',
            'source_ref' => 'device:1',
            'identifier_type' => 'device_user_id',
            'identifier_value' => '77',
            'code_is_guess' => true,
        ]], $tenant->id, 'device', 'device:1');

        expect($service->commit($batch)['created'])->toBe(1)
            ->and(Employee::where('tenant_id', $tenant->id)->value('created_via'))->toBe(EmployeeIntake::VIA_DEVICE);
    });
});

// ── A hire ──────────────────────────────────────────────────────────────────

describe('a hire', function () {
    it('makes the employee through the gateway', function () {
        [$tenant, $hr] = intakeOrganisation();
        $application = IntakeFixtures::approvedOffer($tenant, $hr);

        $this->postJson("/api/v1/job-applications/{$application->public_id}/hire")->assertCreated();

        expect(Employee::where('tenant_id', $tenant->id)->where('email', 'hanna@example.et')->value('created_via'))
            ->toBe(EmployeeIntake::VIA_RECRUITMENT);
    });

    it('refuses an applicant whose phone is a current employee\'s, however it was written', function () {
        [$tenant, $hr] = intakeOrganisation();
        // A record from before phones were stored in one form.
        intakeAbebe($tenant, ['name' => 'Hanna Tesfaye', 'email' => null, 'phone' => '0911223344']);
        $application = IntakeFixtures::approvedOffer($tenant, $hr, ['email' => null]);

        $this->postJson("/api/v1/job-applications/{$application->public_id}/hire")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application');

        expect(intakeHeadcount($tenant))->toBe(1)
            ->and($application->fresh()->employee_id)->toBeNull();
    });

    it('still hires someone who left, or whose record was deleted, as a new record', function () {
        [$tenant, $hr] = intakeOrganisation();
        intakeAbebe($tenant, ['name' => 'Hanna Tesfaye', 'email' => 'hanna@example.et', 'status' => EmployeeStatus::RESIGNED]);
        $application = IntakeFixtures::approvedOffer($tenant, $hr);

        $this->postJson("/api/v1/job-applications/{$application->public_id}/hire")->assertCreated();
        expect(intakeHeadcount($tenant))->toBe(2);

        $deleted = intakeAbebe($tenant, ['name' => 'Dawit Alemu', 'email' => 'dawit@example.et', 'phone' => '+251944556677']);
        $deleted->delete();
        $second = IntakeFixtures::approvedOffer($tenant, $hr, ['name' => 'Dawit Alemu', 'email' => 'dawit@example.et', 'phone' => '+251944556677']);

        $this->postJson("/api/v1/job-applications/{$second->public_id}/hire")->assertCreated();
        expect(Employee::where('tenant_id', $tenant->id)->where('email', 'dawit@example.et')->count())->toBe(1);
    });
});
