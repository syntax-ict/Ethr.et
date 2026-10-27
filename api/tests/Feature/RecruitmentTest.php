<?php

declare(strict_types=1);

use App\Enums\ApplicationStage;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\CurrentTenant;

/**
 * Recruitment from applicant to employee: stages are validated moves, an
 * offer is approved by someone other than whoever made it, and a hire
 * creates exactly one employee however often it is sent.
 */
function recruitmentUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function recruitingVacancy(Tenant $tenant, array $attributes = []): Vacancy
{
    return Vacancy::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Payroll officer',
        'employment_type' => 'full_time',
        'description' => 'Run monthly payroll.',
        'status' => 'open',
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function applicantAt(Vacancy $vacancy, ApplicationStage $stage, array $attributes = []): JobApplication
{
    return JobApplication::query()->create([
        'tenant_id' => $vacancy->tenant_id,
        'vacancy_id' => $vacancy->id,
        'name' => 'Hanna Tesfaye',
        'email' => 'hanna@example.et',
        'phone' => '+251911223344',
        'source' => 'email',
        'stage' => $stage,
        ...$attributes,
    ]);
}

function actAs(User $user): void
{
    app('auth')->forgetGuards();
    test()->actingAs($user);
}

/** An application at the offer stage, made by $maker, approved by $approver. */
function approvedOffer(Vacancy $vacancy, User $maker, User $approver, array $attributes = []): JobApplication
{
    return applicantAt($vacancy, ApplicationStage::OFFER, [
        'offer_start_date' => now()->addWeek()->toDateString(),
        'offer_made_by' => $maker->id,
        'offer_approved_by' => $approver->id,
        'offer_approved_at' => now(),
        ...$attributes,
    ]);
}

it('records an applicant with a history line and an audit entry', function () {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/vacancies/{$vacancy->public_id}/applications"), [
        'name' => 'Hanna Tesfaye',
        'email' => 'Hanna@Example.ET',
        'phone' => '0911223344',
        'source' => 'email',
    ])->assertCreated()
        ->assertJsonPath('stage', 'applied')
        ->assertJsonPath('email', 'hanna@example.et')
        ->assertJsonPath('phone', '+251911223344')
        ->assertJsonPath('events.0.type', 'created')
        ->assertJsonMissingPath('id');

    $this->assertDatabaseHas('audit_log', ['action' => 'recruitment.application_received']);
});

it('flags a possible duplicate without merging it', function () {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant);
    $earlier = applicantAt($vacancy, ApplicationStage::REJECTED);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+251911223344']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson(recruitmentUrl($tenant, "/vacancies/{$vacancy->public_id}/applications"), [
        'name' => 'Hanna T.',
        'email' => 'hanna@example.et',
        'phone' => '+251911223344',
        'source' => 'walk_in',
    ])->assertCreated();

    expect(collect($response->json('possible_duplicates'))->pluck('public_id')->all())
        ->toEqualCanonicalizing([$earlier->public_id, $employee->public_id]);
    expect(JobApplication::query()->count())->toBe(2);
});

it('refuses a stage that skips steps, and never sets hired by hand', function (string $from, string $to) {
    $tenant = createTenant();
    $application = applicantAt(recruitingVacancy($tenant), ApplicationStage::from($from));
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/stage"), [
        'stage' => $to,
        ...($to === 'offer' ? ['offer_start_date' => now()->addWeek()->toDateString()] : []),
    ])->assertUnprocessable()->assertJsonValidationErrors('stage');

    expect($application->fresh()->stage->value)->toBe($from);
})->with([
    'applied straight to offer' => ['applied', 'offer'],
    'applied straight to hired' => ['applied', 'hired'],
    'offer to hired without the hire endpoint' => ['offer', 'hired'],
    'rejected back to screening' => ['rejected', 'screening'],
]);

it('needs a reason to reject, and keeps it on the record', function () {
    $tenant = createTenant();
    $application = applicantAt(recruitingVacancy($tenant), ApplicationStage::SCREENING);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $url = recruitmentUrl($tenant, "/job-applications/{$application->public_id}/stage");

    test()->postJson($url, ['stage' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('note');

    test()->postJson($url, ['stage' => 'rejected', 'note' => 'Below the minimum experience.'])
        ->assertOk()
        ->assertJsonPath('stage', 'rejected')
        ->assertJsonPath('closed_reason', 'Below the minimum experience.')
        ->assertJsonPath('events.0.to_stage', 'rejected');
});

it('keeps interviews and scores in the history', function () {
    $tenant = createTenant();
    $application = applicantAt(recruitingVacancy($tenant), ApplicationStage::INTERVIEW);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/events"), [
        'type' => 'interview',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'score' => 78,
        'note' => 'Strong on reconciliations.',
    ])->assertCreated()->assertJsonPath('score', 78);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/events"), [
        'type' => 'interview',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'score' => 101,
    ])->assertUnprocessable()->assertJsonValidationErrors('score');

    test()->getJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}"))
        ->assertOk()
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.note', 'Strong on reconciliations.');
});

it('lets someone else approve an offer, and not whoever made it', function () {
    $tenant = createTenant();
    $application = applicantAt(recruitingVacancy($tenant), ApplicationStage::INTERVIEW);
    $maker = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/stage"), [
        'stage' => 'offer',
        'offer_start_date' => now()->addWeek()->toDateString(),
        'offer_salary_cents' => 1_500_000,
    ])->assertOk()->assertJsonPath('offer.approved_at', null);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/approve-offer"))
        ->assertUnprocessable()->assertJsonValidationErrors('approver');

    actAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));
    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/approve-offer"))
        ->assertOk();

    expect($application->fresh())
        ->offer_made_by->toBe($maker->id)
        ->offer_approved_at->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'recruitment.offer_approved']);
});

it('refuses to hire before the offer is approved', function () {
    $tenant = createTenant();
    $application = applicantAt(recruitingVacancy($tenant), ApplicationStage::OFFER, [
        'offer_start_date' => now()->addWeek()->toDateString(),
    ]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/hire"))
        ->assertUnprocessable()->assertJsonValidationErrors('stage');

    expect(Employee::query()->count())->toBe(0);
});

it('hires into one employee however many times the hire is sent', function () {
    $tenant = createTenant();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $vacancy = recruitingVacancy($tenant, ['department_id' => $department->id]);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = approvedOffer($vacancy, $hr, createUser(['role' => UserRole::TENANT_ADMIN], $tenant), [
        'offer_salary_cents' => 1_500_000,
    ]);
    $url = recruitmentUrl($tenant, "/job-applications/{$application->public_id}/hire");

    $first = test()->postJson($url, ['status' => 'probation', 'probation_end_date' => now()->addMonths(2)->toDateString()])
        ->assertCreated()
        ->assertJsonPath('name', 'Hanna Tesfaye')
        ->assertJsonPath('status', 'probation')
        ->assertJsonPath('department.name', $department->name)
        ->assertJsonPath('meta.was_duplicate', false);

    test()->postJson($url)
        ->assertOk()
        ->assertJsonPath('public_id', $first->json('public_id'))
        ->assertJsonPath('meta.was_duplicate', true);

    expect(Employee::query()->count())->toBe(1);
    $employee = Employee::query()->sole();
    expect($employee->salary_cents)->toBe(1_500_000)
        ->and($employee->employee_code)->not->toBeNull()
        ->and($application->fresh())
        ->stage->toBe(ApplicationStage::HIRED)
        ->employee_id->toBe($employee->id);
    $this->assertDatabaseHas('audit_log', ['action' => 'recruitment.hired']);
});

it('refuses a probation that ends before it starts', function () {
    $tenant = createTenant();
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = approvedOffer(recruitingVacancy($tenant), $hr, createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/hire"), [
        'status' => 'probation',
        'probation_end_date' => now()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('probation_end_date');
});

it('will not hire a current employee a second time', function () {
    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'hanna@example.et', 'status' => 'confirmed']);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = approvedOffer(recruitingVacancy($tenant), $hr, createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/hire"))
        ->assertUnprocessable()->assertJsonValidationErrors('application');

    expect(Employee::query()->count())->toBe(1);
});

it('stops at the number of positions the vacancy has', function () {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant, ['positions' => 1]);
    applicantAt($vacancy, ApplicationStage::HIRED, ['email' => 'first@example.et', 'phone' => null]);
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = approvedOffer($vacancy, $hr, createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/hire"))
        ->assertUnprocessable()->assertJsonValidationErrors('vacancy');
});

it('shows the offered salary only to someone who may see pay', function () {
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $application = approvedOffer(recruitingVacancy($tenant), $hr, $hr, ['offer_salary_cents' => 1_500_000]);
    $url = recruitmentUrl($tenant, "/job-applications/{$application->public_id}");

    // A recruiter on a custom role: recruitment, and no pay fields.
    $recruiter = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Recruiter']);
    $recruiter->permissions()->sync(Permission::whereIn('name', ['recruitment.manage'])->pluck('id'));
    actAs(createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $recruiter->id], $tenant));
    test()->getJson($url)->assertOk()->assertJsonPath('offer.salary_cents', null);

    actAs($hr);
    test()->getJson($url)->assertOk()->assertJsonPath('offer.salary_cents', 1_500_000);
});

it('erases a closed application and keeps its stages', function () {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant);
    $open = applicantAt($vacancy, ApplicationStage::SCREENING);
    $closed = applicantAt($vacancy, ApplicationStage::REJECTED, ['cover_note' => 'CV attached', 'closed_reason' => 'Not shortlisted']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$open->public_id}/erase"))
        ->assertUnprocessable()->assertJsonValidationErrors('stage');

    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$closed->public_id}/events"), ['type' => 'note', 'note' => 'Called back'])
        ->assertCreated();
    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$closed->public_id}/erase"))
        ->assertOk()
        ->assertJsonPath('erased', true)
        ->assertJsonPath('email', null)
        ->assertJsonPath('phone', null)
        ->assertJsonPath('cover_note', null);

    $fresh = $closed->fresh();
    expect($fresh->stage)->toBe(ApplicationStage::REJECTED)
        ->and($fresh->name)->not->toBe('Hanna Tesfaye')
        ->and($fresh->events()->whereNotNull('note')->count())->toBe(0)
        ->and($fresh->events()->count())->toBe(2);
});

it('keeps recruitment to those who hold it', function (UserRole $role) {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant);
    $application = applicantAt($vacancy, ApplicationStage::APPLIED);
    actingAsUser(['role' => $role], $tenant);

    test()->getJson(recruitmentUrl($tenant, "/vacancies/{$vacancy->public_id}/applications"))->assertForbidden();
    test()->getJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}"))->assertForbidden();
    test()->postJson(recruitmentUrl($tenant, "/vacancies/{$vacancy->public_id}/applications"), [
        'name' => 'X', 'source' => 'email',
    ])->assertForbidden();
    test()->postJson(recruitmentUrl($tenant, "/job-applications/{$application->public_id}/stage"), ['stage' => 'screening'])
        ->assertForbidden();
})->with([
    'employee' => [UserRole::EMPLOYEE],
    'supervisor' => [UserRole::SUPERVISOR],
    'finance admin' => [UserRole::FINANCE_ADMIN],
]);

it('treats another organisation\'s vacancies and applicants as absent', function () {
    $theirs = createTenant();
    $theirVacancy = recruitingVacancy($theirs);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $theirs);
    $theirApplication = approvedOffer($theirVacancy, $hr, createUser(['role' => UserRole::TENANT_ADMIN], $theirs));

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(recruitmentUrl($tenant, "/vacancies/{$theirVacancy->public_id}/applications"))->assertNotFound();
    test()->postJson(recruitmentUrl($tenant, "/vacancies/{$theirVacancy->public_id}/applications"), [
        'name' => 'X', 'source' => 'email',
    ])->assertNotFound();
    // Valid bodies, so each request gets past validation to the lookup.
    test()->getJson(recruitmentUrl($tenant, "/job-applications/{$theirApplication->public_id}"))->assertNotFound();
    foreach ([
        '/stage' => ['stage' => 'withdrawn', 'note' => 'x'],
        '/events' => ['type' => 'note', 'note' => 'x'],
        '/approve-offer' => [],
        '/hire' => [],
        '/erase' => [],
    ] as $path => $body) {
        test()->postJson(recruitmentUrl($tenant, "/job-applications/{$theirApplication->public_id}{$path}"), $body)
            ->assertNotFound();
    }

    app(CurrentTenant::class)->set($theirs);
    expect(Employee::query()->count())->toBe(0)
        ->and($theirApplication->fresh()->stage)->toBe(ApplicationStage::OFFER);
});

it('lists a vacancy\'s applicants furthest along first', function () {
    $tenant = createTenant();
    $vacancy = recruitingVacancy($tenant);
    applicantAt($vacancy, ApplicationStage::APPLIED, ['name' => 'New']);
    applicantAt($vacancy, ApplicationStage::INTERVIEW, ['name' => 'Interviewing']);
    applicantAt(recruitingVacancy($tenant), ApplicationStage::OFFER, ['name' => 'Other vacancy']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(recruitmentUrl($tenant, "/vacancies/{$vacancy->public_id}/applications"))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Interviewing')
        ->assertJsonPath('data.1.name', 'New');
});
